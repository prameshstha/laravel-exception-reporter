# Laravel Exception Reporter

`prameshstha/laravel-exception-reporter` (`Pramesh\LaravelExceptionReporter`) provides two closely related components:

1. **`MailgunHttpService`** — a general-purpose email service that sends email **directly through the Mailgun HTTP API** using Guzzle. It is completely independent of Laravel's normal `Mail` configuration: your application's existing mail driver, mailers, and Mailables are untouched.
2. **`ExceptionReporter`** — a global exception reporter that builds exception/request context, renders an **application-owned Laravel Blade view** into HTML, and sends that HTML through `MailgunHttpService`.

Key principles:

- `ExceptionReporter` is **only one consumer** of `MailgunHttpService`. The Mailgun service is meant for all of your application email (welcome emails, OTPs, reminders, transactional email), not just exceptions.
- `ExceptionReporter` **never generates HTTP responses**. Your application keeps full ownership of 404/401/422/JSON error rendering.
- An exception notification failure is logged and swallowed — it **never masks or worsens the original application exception**.

### Four things this README keeps distinct

| Concept                      | What it is                                                                            | Who renders it                    |
| ---------------------------- | ------------------------------------------------------------------------------------- | --------------------------------- |
| **Laravel Blade rendering**  | `view('welcome', [...])->render()` produces an HTML string inside your app            | **Laravel**, locally              |
| **Mailgun stored templates** | Templates saved in your Mailgun account, referenced by name via the `template` option | **Mailgun**, remotely             |
| **`MailgunHttpService`**     | The transport: posts HTML _or_ a stored-template reference to the Mailgun HTTP API    | —                                 |
| **`ExceptionReporter`**      | One consumer of the service: Blade-renders exception context and sends the HTML       | Laravel renders, Mailgun delivers |

---

## Table of contents

1. [Installation](#1-installation)
2. [Mailgun configuration](#2-mailgun-configuration)
3. [Environment variables](#3-environment-variables)
4. [MailgunHttpService](#4-mailgunhttpservice)
5. [Laravel Blade view → rendered HTML → Mailgun](#5-laravel-blade-view--rendered-html--mailgun)
6. [Mailgun stored templates](#6-mailgun-stored-templates)
7. [Blade templates vs Mailgun templates](#7-blade-templates-vs-mailgun-templates)
8. [Supported send options](#8-supported-mailgun-send-options)
9. [Return value](#9-mailgunhttpservice-return-value)
10. [ExceptionReporter](#10-exceptionreporter)
11. [ExceptionReporter configuration](#11-exceptionreporter-configuration)
12. [Exception Blade template](#12-exception-blade-template)
13. [Rendering Input and Trace in Blade](#13-rendering-input-and-trace-in-blade)
14. [Exception context](#14-exception-context)
15. [HTTP vs CLI exception context](#15-http-vs-cli-exception-context)
16. [Sensitive input redaction](#16-sensitive-input-redaction)
17. [Trace limit](#17-trace-limit)
18. [Complete exception Blade example](#18-complete-exception-blade-example)
19. [Complete MailgunHttpService examples](#19-complete-mailgunhttpservice-examples)
20. [Direct service vs ExceptionReporter](#20-direct-service-vs-exceptionreporter)
21. [Troubleshooting](#21-troubleshooting)
22. [Compatibility](#22-compatibility)
23. [Architecture](#23-architecture--request-flow)

---

## 1. Installation

```bash
composer require prameshstha/laravel-exception-reporter
```

If you install from a Git repository instead of Packagist, first add the repository to your application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/prameshstha/laravel-exception-reporter"
    }
]
```

**Package discovery.** The package registers `Pramesh\LaravelExceptionReporter\ExceptionReporterServiceProvider` through Laravel package discovery (declared in `composer.json` → `extra.laravel.providers`). Nothing needs to be added to `config/app.php`.

**Config publishing (optional).** The provider merges its own defaults with `mergeConfigFrom`, so the package works with zero publishing. To customise `template_map`, `exclude`, `redact`, etc., publish the config:

```bash
php artisan vendor:publish --tag=exception-reporter-config
```

This copies the package config to `config/exception_reporter.php`.

---

## 2. Mailgun configuration

### Package configuration (default)

Out of the box, the service resolved from the container reads the package's own configuration file, `config/exception_reporter.php`, under the `exception_reporter` config key:

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

$result = app(MailgunHttpService::class)->send('user@example.com', [
    'subject' => 'Hello',
    'html' => '<h1>Hello</h1>',
]);
```

The container binding automatically passes the current application environment, so sandbox selection (see below) works without any extra code.

### Custom configuration (bring your own config array)

`MailgunHttpService` accepts a plain configuration **array** in its constructor, so you can instantiate it with your own configuration — for example an application-owned `config/mailgun_http.php`:

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);
```

> **Environment note:** when you construct the service yourself, the third constructor argument (`$environment`) defaults to `'production'`, which means sandbox credentials are **not** used. To get environment-aware sandbox behaviour with a custom config, pass the environment explicitly:
>
> ```php
> $mailgunService = new MailgunHttpService($config, null, app()->environment());
> ```

If you supply a custom configuration, it **must** follow this exact structure:

```php
<?php

declare(strict_types=1);

return [
    'api_key'  => env('MAILGUN_API_KEY'),
    'api_key_sandbox' => env('MAILGUN_API_KEY_SANDBOX'),
    'region'   => env('MAILGUN_REGION', 'us'),
    'endpoint' => env('MAILGUN_ENDPOINT', 'https://api.mailgun.net'),
    'version'  => env('MAILGUN_VERSION', 'v3'),
    'from'     => [
        'address' => env('DEFAULT_FROM_EMAIL', 'support@example.com'),
        'name'    => env('DEFAULT_FROM_NAME', 'Example Application'),
    ],

    'domains' => [
        'sandbox' => env('MAILGUN_DOMAIN_SANDBOX', 'sandboxXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX.mailgun.org'),
        'default'       => env('MAILGUN_DOMAIN', 'mg.example.com'),
        'transactional' => env('MAILGUN_DOMAIN_TRANSACTIONAL', 'mg.example.com'),
        'marketing'     => env('MAILGUN_DOMAIN_MARKETING', 'marketing.example.com'),
    ],

    'template_map' => [
        'email_otp' => [
            'template' => 'email_otp',
            'tags'     => [],
        ],

        'welcome' => [
            'template' => 'program_welcome',
            'tags'     => ['program_welcome_email'],
        ],
    ],
];
```

_(Values above are placeholders — never commit real API keys or secrets.)_

### Configuration keys explained

| Key                       | Meaning                                                                                                                                                                                                            |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `api_key`                 | Production Mailgun private API key. Used for Basic auth as `['api', $apiKey]`.                                                                                                                                     |
| `api_key_sandbox`         | Mailgun sandbox API key. Used automatically in non-production environments when both it and `domains.sandbox` are set.                                                                                             |
| `region`                  | `us` (default) or `eu`. When set to `eu` **and** `endpoint` is still the default `https://api.mailgun.net`, the service switches to `https://api.eu.mailgun.net`. An explicitly customised `endpoint` always wins. |
| `endpoint`                | Base URL of the Mailgun API. Default `https://api.mailgun.net`.                                                                                                                                                    |
| `version`                 | Mailgun API version path segment. Default `v3`. Requests post to `{version}/{domain}/messages`.                                                                                                                    |
| `from.address`            | Default sender email address, used when a send call has no `from` option.                                                                                                                                          |
| `from.name`               | Default sender display name. When present, the sender is formatted as `Name <address>`; otherwise just the address.                                                                                                |
| `domains.sandbox`         | Mailgun sandbox sending domain. Used whenever sandbox mode is active (overrides the `domain` send option).                                                                                                         |
| `domains.default`         | The sending domain used when a send call has no `domain` option, or when the requested domain key is missing/empty.                                                                                                |
| `domains.transactional`   | A named sending domain selectable via `'domain' => 'transactional'`.                                                                                                                                               |
| `domains.marketing`       | A named sending domain selectable via `'domain' => 'marketing'`.                                                                                                                                                   |
| `template_map`            | Maps application-level template keys to Mailgun stored templates.                                                                                                                                                  |
| `template_map.*.template` | The **actual** stored template name in your Mailgun account.                                                                                                                                                       |
| `template_map.*.tags`     | Tags automatically merged into the send when that template key is used.                                                                                                                                            |

The package config additionally supports `timeout` (Guzzle timeout in seconds, default `10`), `production_environments` (environments that use production credentials, default `['production', 'staging']`), and the `exception` block documented in [section 11](#11-exceptionreporter-configuration).

---

## 3. Environment variables

```dotenv
MAILGUN_API_KEY=
MAILGUN_API_KEY_SANDBOX=
MAILGUN_REGION=us
MAILGUN_ENDPOINT=https://api.mailgun.net
MAILGUN_VERSION=v3

DEFAULT_FROM_EMAIL=
DEFAULT_FROM_NAME=

MAILGUN_DOMAIN_SANDBOX=
MAILGUN_DOMAIN=
MAILGUN_DOMAIN_TRANSACTIONAL=
MAILGUN_DOMAIN_MARKETING=
```

Each variable maps one-to-one onto a configuration key: `MAILGUN_API_KEY` → `api_key`, `MAILGUN_API_KEY_SANDBOX` → `api_key_sandbox`, `MAILGUN_REGION` → `region`, `MAILGUN_ENDPOINT` → `endpoint`, `MAILGUN_VERSION` → `version`, `DEFAULT_FROM_EMAIL`/`DEFAULT_FROM_NAME` → `from.address`/`from.name`, and the four `MAILGUN_DOMAIN*` variables → the `domains` map (`sandbox`, `default`, `transactional`, `marketing`).

Exception-reporter variables (`EXCEPTION_EMAIL_*`) are documented in [section 11](#11-exceptionreporter-configuration).

### US / EU region behaviour

The service resolves its Guzzle `base_uri` from `endpoint`. There is one convenience rule: if `region` is `eu` **and** `endpoint` is still the default `https://api.mailgun.net`, the endpoint automatically becomes `https://api.eu.mailgun.net`. If you set a custom `MAILGUN_ENDPOINT`, that exact value is used regardless of region.

### Sandbox behaviour

Sandbox mode is decided per service instance:

1. If the application environment (the `$environment` constructor argument; the container binding passes `app()->environment()`) is listed in `production_environments` (default `production`, `staging`) → **production** `api_key` and the requested domain are used.
2. Otherwise, sandbox mode activates **only if both** `api_key_sandbox` **and** `domains.sandbox` are configured. In sandbox mode, `api_key_sandbox` is used and the sandbox domain **overrides whatever `domain` option was requested**.
3. If either sandbox value is missing, the service falls back to the production key/domain even in local environments.

---

## 4. MailgunHttpService

Class: `Pramesh\LaravelExceptionReporter\MailgunHttpService`

### Constructor

```php
public function __construct(
    protected array $config,
    protected ?ClientInterface $client = null,
    protected string $environment = 'production'
)
```

- **`$config`** — the configuration array (structure in [section 2](#2-mailgun-configuration)):

  ```php
  $config = config('mailgun_http');

  $mailgunService = new MailgunHttpService($config);
  ```

- **`$client`** _(optional)_ — a Guzzle `ClientInterface`. Pass one to inject a mock in tests; when `null`, the service lazily builds its own client with `base_uri`, Basic auth (`['api', $apiKey]`), and the configured `timeout` (default 10 seconds).
- **`$environment`** _(optional)_ — the application environment string used for sandbox selection. Defaults to `'production'`. The package's container binding passes `app()->environment()` automatically.

### send()

```php
public function send(string|array $to, array $options = []): array
```

**The recipient is the FIRST argument.** It can be a single address or an array of addresses:

```php
$mailgunService->send('pramesh@shresthapramesh.comnp', [
    'subject' => 'Test Email',
    'html' => '<h1>Test Email</h1><p>This is a test email.</p>',
]);

$mailgunService->send(['one@example.com', 'two@example.com'], [
    'subject' => 'Test Email',
    'html' => '<h1>Test Email</h1>',
]);
```

There is **no `to` key inside the options array** — do not add one. The recipient is already provided as the first argument; a `to` entry in `$options` is simply ignored by the implementation.

### renderMailable()

```php
public function renderMailable(Mailable $mailable): string
```

Renders a Laravel Mailable to an HTML string (e.g. `$html = $service->renderMailable(new WelcomeMail($user));`). The Mailable is only _rendered_ — Laravel Mail is never used to send. The resulting HTML can be passed to `send()` as the `html` option.

---

## 5. Laravel Blade view → rendered HTML → Mailgun

A Laravel Blade view is rendered **locally by Laravel** into a plain HTML string:

```php
$html = view('welcome', [])->render();
```

The second argument (`[]` above) is the array of variables made available inside the Blade template:

```php
$html = view('welcome', [
    'name' => 'Pramesh',
])->render();
```

`resources/views/welcome.blade.php` can then use the variable:

```blade
<h1>Welcome {{ $name }}</h1>
```

Every key in the array becomes a Blade variable:

```php
$html = view('welcome', [
    'name' => 'Pramesh',
    'company' => 'Example',
])->render();
```

```blade
<h1>Hello {{ $name }}</h1>
<p>Company: {{ $company }}</p>
```

The rendered HTML string is then handed to Mailgun via the `html` option:

```php
$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'html' => $html,
]);
```

The complete flow:

```
Laravel Blade view
    ->
view(...)->render()
    ->
HTML string
    ->
MailgunHttpService::send()
    ->
Mailgun HTTP API
```

Complete copy/paste example:

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$to = 'pramesh@shresthapramesh.com.np';

$html = view('welcome', [])->render();

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'html' => $html,
]);

return $result;
```

---

## 6. Mailgun stored templates

Alternatively, the email body can be a **template stored in your Mailgun account**. In this approach Laravel renders nothing — the template name and its variables are sent to Mailgun, and **Mailgun renders the template remotely**.

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$to = 'pramesh@shresthapramesh.com.np';

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'template' => 'welcome',
    'template_vars' => [
        'name' => 'Pramesh',
    ],
]);

return $result;
```

### template_map resolution

The `template` value is looked up in the configured `template_map`:

```php
'template_map' => [
    'welcome' => [
        'template' => 'program_welcome',
        'tags' => ['program_welcome_email'],
    ],
],
```

Your application code uses the friendly key:

```php
'template' => 'welcome'
```

but the actual Mailgun stored template that gets sent is:

```
program_welcome
```

and the mapped `tags` (`program_welcome_email`) are merged into the message automatically. If the `template` value has no entry in `template_map`, it is sent to Mailgun **as-is** — so unmapped template names still work.

`template_vars` are passed to Mailgun (as the `X-Mailgun-Variables` payload) and substituted into the stored template **by Mailgun**, not by Laravel. This is completely separate from Blade variables.

> Note: when `template` is provided, it takes precedence and any `html` option is ignored. The package only _sends_ with existing stored templates — it does not create, update, or delete Mailgun templates.

---

## 7. Blade templates vs Mailgun templates

The package supports **both** approaches. They are easy to confuse — keep them apart.

### Laravel Blade template

```php
$html = view('welcome', [
    'name' => 'Pramesh',
])->render();
```

This is rendered **by Laravel**, inside your application, using a `.blade.php` file in `resources/views/`. Then:

```php
$mailgunService->send($to, [
    'subject' => 'Test Email',
    'html' => $html,
]);
```

**Mailgun receives the already-rendered HTML.** Mailgun knows nothing about your Blade files or variables.

### Mailgun stored template

```php
$mailgunService->send($to, [
    'subject' => 'Test Email',
    'template' => 'welcome',
    'template_vars' => [
        'name' => 'Pramesh',
    ],
]);
```

**Mailgun renders the stored template** in its own infrastructure, substituting `template_vars`. Your application sends only the template _name_ and the variables — no HTML.

|                             | Blade template                | Mailgun stored template        |
| --------------------------- | ----------------------------- | ------------------------------ |
| Template lives in           | `resources/views/*.blade.php` | Your Mailgun account           |
| Rendered by                 | Laravel (`view()->render()`)  | Mailgun                        |
| Variables passed via        | `view()`'s second argument    | `template_vars` option         |
| Send option used            | `html`                        | `template` (+ `template_vars`) |
| Used by `ExceptionReporter` | ✅ yes                        | ❌ no                          |

---

## 8. Supported Mailgun send options

`send()` supports exactly these options: `subject`, `html`, `template`, `template_vars`, `tags`, `from`, `attachments`, `domain`. (There is **no `text` option** — plain-text bodies are not implemented.)

### subject

```php
'subject' => 'Test Email'
```

The email subject line. Defaults to an empty string when omitted.

### html

```php
'html' => $html
```

The full HTML body. Typically generated by Laravel Blade:

```php
$html = view('welcome', [
    'name' => 'Pramesh',
])->render();
```

Ignored if `template` is also provided.

### template

```php
'template' => 'welcome'
```

A Mailgun stored-template reference. Resolved through `template_map` when a mapping exists (`welcome` → `program_welcome` in the example above); sent as-is otherwise.

### template_vars

```php
'template_vars' => [
    'name' => 'Pramesh',
]
```

Variables sent to the **Mailgun stored template** (JSON-encoded into the `h:X-Mailgun-Variables` field). Only meaningful together with `template`.

### tags

```php
'tags' => [
    'welcome',
    'transactional',
]
```

Mailgun analytics tags. Each tag is sent as its own `o:tag` field, so multiple tags are fully supported. When a mapped stored template is used, its configured `template_map.*.tags` are merged with any tags passed here, and duplicates are removed.

### from

```php
'from' => [
    'address' => 'sender@example.com',
    'name' => 'Sender Name',
]
```

Per-email sender override. Behaviour:

- No `from` option → the configured default `from.address` / `from.name` is used.
- With a `name` → formatted as `Sender Name <sender@example.com>`.
- **Per-email `address` supplied without a `name` key** → only the plain address is used. The default `from.name` is deliberately _not_ inherited, so an override never gets someone else's display name attached.

### attachments

```php
'attachments' => [
    [
        'fullpath' => storage_path('app/example.pdf'),
        'filename' => 'example.pdf',
    ],
]
```

Files are streamed to Mailgun as multipart `attachment` fields. Entries whose `fullpath` is missing or **not readable are silently skipped** (the rest of the email still sends). If `filename` is omitted, the file's basename is used.

### domain

```php
'domain' => 'transactional'
```

This is a **key into the configured `domains` map**, not a literal Mailgun domain:

```php
'domains' => [
    'default' => 'mg.example.com',
    'transactional' => 'mg.example.com',
    'marketing' => 'marketing.example.com',
]
```

So `'domain' => 'marketing'` selects `marketing.example.com`. If the option is omitted, or the requested key is missing/empty, `domains.default` is used. In sandbox mode, `domains.sandbox` overrides this selection entirely.

---

## 9. MailgunHttpService return value

`send()` **never throws**. It always returns a predictable array.

Success:

```php
[
    'ok' => true,
    'status' => 200,
    'body' => ...,   // decoded JSON array when possible, raw body string otherwise
]
```

Failure:

```php
[
    'ok' => false,
    'status' => 400,
    'error' => ...,  // Mailgun response body when available, otherwise the exception message
]
```

Error-handling behaviour, exactly as implemented:

- Guzzle `RequestException` (4xx/5xx and transport errors that carry a request) is caught; the HTTP **status** and the **response body** are captured when a response exists (status `0` when it does not).
- Any other `Throwable` is caught and reported with status `0`.
- Every failure is logged via `Log::error('Mailgun send failed: ...')` (credentials are never logged).
- If the API key or the resolved sending domain is missing, the service returns `['ok' => false, 'status' => 0, ...]` **without** making an HTTP request, and logs the reason.
- Normal Mailgun request failures therefore never escape as exceptions — check `$result['ok']`.

---

## 10. ExceptionReporter

Class: `Pramesh\LaravelExceptionReporter\ExceptionReporter`

Its purpose is to email a rich, readable notification whenever your application reports an exception — without ever interfering with the application's own exception handling or HTTP responses.

When `report(Throwable $exception)` is called, the reporter:

1. Reads the exception-reporter configuration (`config('exception_reporter.exception')`).
2. Checks whether reporting is **enabled** (returns immediately when disabled).
3. Checks the **excluded** exception types (returns when the exception is an instance of any).
4. Resolves the **recipient(s)** (logs a warning and returns when none are configured).
5. Builds the exception/request **context** (see [section 14](#14-exception-context)).
6. Renders the configured **Laravel Blade view** with that context.
7. Sends the rendered HTML through **`MailgunHttpService`**.
8. Logs an error if Mailgun **rejects** the message.
9. Catches **any** failure inside itself (broken view, bad config, transport error) and logs `Exception notification failed: ...` — the original application exception is never masked.

The relevant rendering flow inside the reporter:

```php
$html = view(
    $config['view'] ?? 'emails.exception',
    $this->context($exception)
)->render();

$result = $this->mailer->send($to, [
    'subject' => $config['subject'] ?? 'Laravel Application Exception',
    'html' => $html,
    'domain' => $config['domain'] ?? 'default',
]);
```

Note that the exception email uses the **Laravel Blade approach** (`html` option), _not_ a Mailgun stored template: the view lives in and is rendered by your application, and Mailgun only delivers the finished HTML.

### Integrating with your exception handling

Call the reporter from your **existing** exception handling — it does not replace anything.

Laravel 12/13 (`bootstrap/app.php`):

```php
use Pramesh\LaravelExceptionReporter\ExceptionReporter;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        app(ExceptionReporter::class)->report($e);
    });

    // Your own $exceptions->render(...) logic stays untouched.
})
```

Laravel 8 (`app/Exceptions/Handler.php`):

```php
public function register()
{
    $this->reportable(function (Throwable $e) {
        app(\Pramesh\LaravelExceptionReporter\ExceptionReporter::class)->report($e);
    });
}
```

---

## 11. ExceptionReporter configuration

All reporter settings live under the `exception` key of `config/exception_reporter.php`, each mapped to an environment variable:

```php
'exception' => [

    'enabled' => env('EXCEPTION_EMAIL_ENABLED', false),

    'to' => env('EXCEPTION_EMAIL_TO'),

    'subject' => env('EXCEPTION_EMAIL_SUBJECT', 'Laravel Application Exception'),

    // Key into the "domains" map (not a literal Mailgun domain).
    'domain' => env('EXCEPTION_EMAIL_DOMAIN', 'default'),

    // Application-owned Blade view name.
    'view' => env('EXCEPTION_EMAIL_VIEW', 'emails.exception'),

    // Falls back to config('app.timezone') when empty.
    'timezone' => env('EXCEPTION_EMAIL_TIMEZONE'),

    // Maximum stack-trace entries in the email.
    'trace_limit' => 15,

    'exclude' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    'redact' => [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_token',
        'access_token',
        'secret',
    ],

],
```

| Key           | Env var                    | Meaning                                                                                                                                                                          |
| ------------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `enabled`     | `EXCEPTION_EMAIL_ENABLED`  | Master switch. Defaults to `false` — nothing is sent until you enable it.                                                                                                        |
| `to`          | `EXCEPTION_EMAIL_TO`       | Recipient(s). Accepts a **single address**, a **comma-separated string**, or an **array** (when set in the published config). Missing recipients skip sending and log a warning. |
| `subject`     | `EXCEPTION_EMAIL_SUBJECT`  | Email subject. Default `Laravel Application Exception`.                                                                                                                          |
| `domain`      | `EXCEPTION_EMAIL_DOMAIN`   | Domain-map **key** passed to `MailgunHttpService` (e.g. `default`, `transactional`).                                                                                             |
| `view`        | `EXCEPTION_EMAIL_VIEW`     | The application-owned Blade view name (e.g. `emails.exception` → `resources/views/emails/exception.blade.php`). The package ships **no** view.                                   |
| `timezone`    | `EXCEPTION_EMAIL_TIMEZONE` | Timezone for the `Time` context value. Falls back to `config('app.timezone')`.                                                                                                   |
| `trace_limit` | —                          | Number of trace entries included (default `15`).                                                                                                                                 |
| `exclude`     | —                          | Exception classes that never trigger an email (`instanceof` check, so subclasses are excluded too).                                                                              |
| `redact`      | —                          | Request input keys replaced with `[REDACTED]` (recursive).                                                                                                                       |

Practical `.env` example:

```dotenv
EXCEPTION_EMAIL_ENABLED=true
EXCEPTION_EMAIL_TO=developer@example.com,admin@example.com
EXCEPTION_EMAIL_SUBJECT="[MyApp] Exception"
EXCEPTION_EMAIL_DOMAIN=default
EXCEPTION_EMAIL_VIEW=emails.exception
EXCEPTION_EMAIL_TIMEZONE=Australia/Melbourne
```

---

## 12. Exception Blade template

The reporter renders a normal Laravel Blade template, and the context array is passed **directly** into the view:

```php
$html = view(
    $config['view'] ?? 'emails.exception',
    $this->context($exception)
)->render();
```

Therefore **each key returned by `context()` becomes a Blade variable** in your template:

| Variable     | Contents                                                                        |
| ------------ | ------------------------------------------------------------------------------- |
| `$Message`   | The exception message (`getMessage()`).                                         |
| `$Exception` | The fully-qualified exception class name.                                       |
| `$File`      | The file in which the exception was thrown.                                     |
| `$Line`      | The line number.                                                                |
| `$URL`       | The full request URL, or `console` outside HTTP.                                |
| `$Method`    | The HTTP method, or `CLI` outside HTTP.                                         |
| `$IP`        | The client IP, or `null` outside HTTP.                                          |
| `$Input`     | **Array** of sanitized (redacted) request input; `[]` outside HTTP.             |
| `$Timezone`  | The effective timezone used for `$Time`.                                        |
| `$Time`      | Date/time string generated in that timezone.                                    |
| `$Trace`     | **Array** of limited stack-trace entries (`file`, `line`, `function`, `class`). |
| `$all`       | The original exception object itself, for anything else you need.               |

---

## 13. Rendering Input and Trace in Blade

**`$Input` and `$Trace` are arrays.** Do **not** write:

```blade
{{ $Input }}
{{ $Trace }}
```

Arrays cannot be rendered directly as strings — Blade will fail with an _"Array to string conversion"_ / htmlspecialchars error, which would then be caught and logged by the reporter instead of producing your email.

The correct, readable approach is to JSON-encode them:

```blade
<pre>{{ json_encode($Input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
```

```blade
<pre>{{ json_encode($Trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
```

`JSON_PRETTY_PRINT` formats the structures across indented lines and `JSON_UNESCAPED_SLASHES` keeps file paths readable, which makes the exception email easy to scan. (You can also `@foreach` over `$Trace` for custom markup — each entry has `file`, `line`, `function`, `class`.)

---

## 14. Exception context

The public method `context(Throwable $exception): array` builds exactly:

```php
[
    'Message' => $exception->getMessage(),
    'Exception' => get_class($exception),
    'File' => $exception->getFile(),
    'Line' => $exception->getLine(),
    'URL' => $request['url'],
    'Method' => $request['method'],
    'IP' => $request['ip'],
    'Input' => $request['input'],
    'Timezone' => $timezone,
    'Time' => Carbon::now($timezone)->toDateTimeString(),
    'Trace' => $this->trace($exception, (int) ($config['trace_limit'] ?? 15)),
    'all' => $exception,
]
```

- **Message** — the exception message.
- **Exception** — the exception class.
- **File** / **Line** — where the exception was thrown.
- **URL** / **Method** / **IP** — current request details when an HTTP request is available (see next section).
- **Input** — sanitized request input (see [redaction](#16-sensitive-input-redaction)).
- **Timezone** — the configured `timezone`, falling back to the application timezone.
- **Time** — generated with Carbon in that timezone.
- **Trace** — a limited stack trace (see [trace limit](#17-trace-limit)).
- **all** — the original exception object.

---

## 15. HTTP vs CLI exception context

Exceptions can be reported from:

- HTTP requests
- queue jobs
- scheduled tasks
- console commands

The reporter never assumes an HTTP request exists. When the application is running in console, no request is bound, or reading the request fails, the fallback context is:

```
url    = console
method = CLI
ip     = null
input  = []
```

So queue/scheduler/Artisan exceptions produce a complete, valid email — the request-specific fields simply show these CLI values.

---

## 16. Sensitive input redaction

Request input is **recursively** sanitized against the configurable `redact` key list before it is placed in the email. Input like:

```php
[
    'email' => 'user@example.com',
    'password' => 'super-secret',
    'nested' => [
        'api_token' => 'abc123',
        'ok' => 'value',
    ],
]
```

becomes (with `password` and `api_token` in the redact list):

```php
[
    'email' => 'user@example.com',
    'password' => '[REDACTED]',
    'nested' => [
        'api_token' => '[REDACTED]',
        'ok' => 'value',
    ],
]
```

Redaction matches keys at **any nesting depth**. Defaults: `password`, `password_confirmation`, `current_password`, `token`, `api_token`, `access_token`, `secret`. Add your own keys in the published config. Mailgun credentials are never logged, and the context contains no request headers or configuration values.

---

## 17. Trace limit

`trace_limit` controls how many stack-trace entries are included in the exception email. The default is **15** when no configuration is supplied. Each entry contains `file`, `line`, `function`, and `class` (with `[internal]`/`null` placeholders for internal frames). Limiting the trace keeps the email readable instead of dumping hundreds of frames.

---

## 18. Complete exception Blade example

`resources/views/emails/exception.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1a1a1a;">

    <h1 style="color: #b91c1c;">{{ $Message }}</h1>

    <table cellpadding="6" cellspacing="0" border="0">
        <tr><td><strong>Exception</strong></td><td>{{ $Exception }}</td></tr>
        <tr><td><strong>File</strong></td><td>{{ $File }}</td></tr>
        <tr><td><strong>Line</strong></td><td>{{ $Line }}</td></tr>
        <tr><td><strong>URL</strong></td><td>{{ $URL }}</td></tr>
        <tr><td><strong>Method</strong></td><td>{{ $Method }}</td></tr>
        <tr><td><strong>IP</strong></td><td>{{ $IP ?? 'n/a' }}</td></tr>
        <tr><td><strong>Timezone</strong></td><td>{{ $Timezone }}</td></tr>
        <tr><td><strong>Time</strong></td><td>{{ $Time }}</td></tr>
    </table>

    <h3>Input</h3>
    <pre style="background: #f4f4f5; padding: 12px; overflow-x: auto;">{{ json_encode($Input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

    <h3>Trace</h3>
    <pre style="background: #f4f4f5; padding: 12px; overflow-x: auto;">{{ json_encode($Trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

</body>
</html>
```

Then configure it:

```dotenv
EXCEPTION_EMAIL_VIEW=emails.exception
```

---

## 19. Complete MailgunHttpService examples

### Simple HTML

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'html' => '<h1>Test Email</h1><p>This is a test email.</p>',
]);

return $result;
```

### Laravel Blade rendered HTML

```php
$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$to = 'pramesh@shresthapramesh.com.np';

$html = view('welcome', [])->render();

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'html' => $html,
]);

return $result;
```

### Laravel Blade with variables

```php
$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$html = view('welcome', [
    'name' => 'Pramesh',
])->render();

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Welcome',
    'html' => $html,
]);

return $result;
```

### Mailgun stored template

```php
$config = config('mailgun_http');

$mailgunService = new MailgunHttpService($config);

$result = $mailgunService->send('pramesh@shresthapramesh.com.np', [
    'subject' => 'Test Email',
    'template' => 'welcome',
    'template_vars' => [
        'name' => 'Pramesh',
    ],
]);

return $result;
```

With this `template_map`:

```php
'template_map' => [
    'welcome' => [
        'template' => 'program_welcome',
        'tags' => ['program_welcome_email'],
    ],
],
```

the call above looks up the `welcome` key, sends the **actual** Mailgun stored template `program_welcome`, and automatically attaches the `program_welcome_email` tag. Mailgun then substitutes `template_vars` (`name`) into the stored template on its side.

---

## 20. Direct service vs ExceptionReporter

**Use `MailgunHttpService` directly when:**

- Sending arbitrary application emails.
- Sending simple HTML.
- Rendering a Laravel Blade view and sending the resulting HTML.
- Sending Mailgun stored templates with `template_vars`.
- Using tags for Mailgun analytics.
- Sending attachments.
- Selecting a sending domain (`domain` option).
- Selecting/overriding the sender (`from` option).

**Use `ExceptionReporter` when:**

- Automatically reporting application exceptions.
- Sending a standardized exception email across projects.
- Including exception + request context (URL, method, IP, input, time, trace).
- Redacting sensitive request data automatically.
- Limiting the stack trace to a readable length.
- Guaranteeing that notifier failures never interfere with the original exception.

---

## 21. Troubleshooting

### 401 Unauthorized

`['ok' => false, 'status' => 401, ...]` almost always means the Mailgun authentication/API key is invalid or wrong for the domain being used. Check:

- `MAILGUN_API_KEY` — correct private API key for your Mailgun account.
- `MAILGUN_API_KEY_SANDBOX` — correct sandbox key, if sandbox mode is active.
- **Application environment** — remember that outside `production`/`staging` (per `production_environments`), the sandbox key/domain are used when both are configured. A wrong sandbox key surfaces as 401 only in local/dev.
- **Cached Laravel configuration** — a stale cache keeps old credentials alive (see below).
- The credentials in the Mailgun dashboard itself (key not rotated/revoked).

Never paste API keys into logs or bug reports.

### 400 "to" parameter is invalid / missing

The recipient must be a valid address, passed as the **first argument**:

```php
$mailgunService->send($to, [
    // options
]);
```

Do not place `to` inside the options array — the implementation does not read it from there.

### 400 missing email content

Mailgun requires body content. With this package the supported content options are:

- `'html' => $html` — for the Laravel Blade / raw HTML flow, or
- `'template' => 'welcome'` (with `'template_vars' => [...]`) — for the Mailgun stored-template flow.

A `text` option is **not** implemented; sending neither `html` nor `template` results in a content error from Mailgun.

### Missing key or domain (status 0, no HTTP call)

`['ok' => false, 'status' => 0, 'error' => 'Missing Mailgun API key or sending domain.']` means the resolved API key or domain was empty — check the env vars for the environment you're in (production key/domain vs sandbox pair).

### Laravel config cache

If configuration or `.env` changes don't seem to take effect:

```bash
php artisan config:clear
```

and in deployed environments, rebuild the cache after changing env values:

```bash
php artisan config:cache
```

The package reads values via `config()` (never `env()` at runtime), so config caching is fully supported — just remember to rebuild it on deploy.

### Exception emails not arriving

- `EXCEPTION_EMAIL_ENABLED=true`? (Default is `false`.)
- `EXCEPTION_EMAIL_TO` set? (Missing recipients log a warning and skip.)
- Is the exception class in the `exclude` list (404s, validation, auth are excluded by default)?
- Does the configured Blade view exist and render without errors? Check `laravel.log` for `Exception notification failed: ...` — the reporter logs every internal failure instead of throwing.

---

## 22. Compatibility

From the package `composer.json`:

| Requirement                                            | Constraint                                   |
| ------------------------------------------------------ | -------------------------------------------- |
| PHP                                                    | `^8.0`                                       |
| Laravel (`illuminate/support`, `illuminate/contracts`) | `^8.0 \| ^12.0 \| ^13.0` (Laravel 8, 12, 13) |
| Guzzle (`guzzlehttp/guzzle`)                           | `^7.2`                                       |

Dev/test tooling: Orchestra Testbench `^6.0|^10.0|^11.0`, PHPUnit `^9.5|^10.5|^11.0`. CI runs the matrix Laravel 8 / PHP 8.0, Laravel 12 / PHP 8.3, Laravel 13 / PHP 8.4. The tests mock Mailgun with Guzzle's `MockHandler`, so no real credentials are needed to run `vendor/bin/phpunit`.

---

## 23. Architecture / request flow

```
Application
    |
    +--> MailgunHttpService
    |       |
    |       +--> Guzzle
    |       |
    |       +--> Mailgun HTTP API
    |
    +--> ExceptionReporter
            |
            +--> context()
            |
            +--> Laravel Blade view
            |
            +--> rendered HTML
            |
            +--> MailgunHttpService
            |
            +--> Mailgun HTTP API
```

`ExceptionReporter` never talks to Mailgun itself — it builds context, Blade-renders the application's view, and **delegates actual email delivery to `MailgunHttpService`**, which is the single transport for all package email. The service, in turn, talks only to the Mailgun HTTP API via Guzzle and is entirely independent of Laravel's `Mail` system, so your application's normal mail configuration continues to work untouched alongside it.

## License

MIT
