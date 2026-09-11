# Laravel Exception Notifier

Internal company package providing two things:

1. **`MailgunHttpService`** — a reusable, general-purpose email service that sends directly through the Mailgun HTTP API (Guzzle). It is completely independent of the application's Laravel `Mail` configuration.
2. **`ExceptionReporter`** — a global exception notifier that renders an application-owned Blade view and emails it through the Mailgun service.

Build once, reuse across company Laravel applications. The package deliberately has **no** models, migrations, controllers, routes, queues, notifications, or UI.

## Supported versions

| Package release line | Laravel   | PHP   |
| -------------------- | --------- | ----- |
| **v1.x** (current)   | 8, 12, 13 | ≥ 8.0 |

Composer constraint in v1.x: `"illuminate/support": "^8.0|^12.0|^13.0"`, `"php": "^8.0"`.

> The PHP floor is set by the company's Laravel 8 applications (assumed PHP ≥ 8.0). If those apps run a newer PHP, the floor can be raised in a minor release note — raising it only becomes a breaking change if an installed app would be cut off.

### Version strategy — different apps, different package versions

The package version is **not** the Laravel version. Normal semver applies (`1.0.0`, `1.1.0`, `2.0.0`), with Laravel compatibility documented per release line.

Each application simply requires a release line compatible with its Laravel version, and Composer resolves the newest release that satisfies **that application's** dependencies:

```bash
# Laravel 8 application
composer require your-company/laravel-exception-notifier:^1.0

# Laravel 12 or 13 application (today)
composer require your-company/laravel-exception-notifier:^1.0

# Laravel 12/13 application later, once a v2 exists
composer require your-company/laravel-exception-notifier:^2.0
```

Because v1.x declares `^8.0|^12.0|^13.0`, a Laravel 8 app pinned to `^1.0` can never be pushed onto a release that requires Laravel 12+ — Composer's resolver guarantees it. Different applications using different package versions at the same time is expected and fine.

**Adding a future Laravel version (14, 15, …):** test the existing code on it (add a CI matrix row); if it passes, widen the constraint (e.g. `^8.0|^12.0|^13.0|^14.0`) in a **minor** release. No major version just because Laravel released one.

**Dropping Laravel 8 later:** once no company app runs Laravel 8, release **v2.0.0** with e.g. `"illuminate/support": "^12.0|^13.0|^14.0"` (and a raised PHP floor if wanted). Laravel 8 apps stay on `^1.0` — they keep working and keep receiving any v1.x patches until they're upgraded. Nothing breaks for them when v2 ships.

## Installation (GitHub, not Packagist)

The package is not on Packagist, so each consuming application must register the Git repository in its `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/your-company/laravel-exception-notifier"
    }
]
```

Then:

```bash
composer require your-company/laravel-exception-notifier:^1.0
```

Composer reads the tags on the repository, picks the newest tag satisfying `^1.0` **and** the app's Laravel version, and installs it under `vendor/your-company/laravel-exception-notifier/`. Laravel package discovery registers the service provider automatically — never copy the files into `app/`.

Optionally publish the config (not required — defaults are merged):

```bash
php artisan vendor:publish --tag=mailgun-config
```

## Environment variables

```dotenv
MAILGUN_API_KEY=
MAILGUN_API_KEY_SANDBOX=

MAILGUN_ENDPOINT=https://api.mailgun.net
MAILGUN_VERSION=v3
MAILGUN_REGION=us

MAILGUN_DOMAIN=
MAILGUN_DOMAIN_SANDBOX=
MAILGUN_DOMAIN_TRANSACTIONAL=
MAILGUN_DOMAIN_MARKETING=

DEFAULT_FROM_EMAIL=
DEFAULT_FROM_NAME=

EXCEPTION_EMAIL_ENABLED=false
EXCEPTION_EMAIL_TO=
EXCEPTION_EMAIL_SUBJECT="Laravel Application Exception"
EXCEPTION_EMAIL_DOMAIN=default
EXCEPTION_EMAIL_VIEW=emails.exception
EXCEPTION_EMAIL_TIMEZONE=
```

Only configure what you use. Never commit real credentials.

### Sandbox behaviour

In environments **not** listed in `mailgun_http.production_environments` (default: `production`, `staging`), the service automatically uses `MAILGUN_API_KEY_SANDBOX` + `MAILGUN_DOMAIN_SANDBOX` — provided both are configured — otherwise it falls back to the production key/domain.

## Sending email

```php
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

// Raw HTML
app(MailgunHttpService::class)->send('user@example.com', [
    'html' => '<h1>Hello</h1>',
    'subject' => 'Hello',
]);

// Multiple recipients
app(MailgunHttpService::class)->send(
    ['one@example.com', 'two@example.com'],
    ['html' => $html, 'subject' => 'Hello']
);
```

### Mailgun stored templates

Map application template keys in `config/mailgun_http.php`:

```php
'template_map' => [
    'welcome' => [
        'template' => 'program_welcome',
        'tags' => ['program_welcome_email'],
    ],
],
```

```php
app(MailgunHttpService::class)->send('user@example.com', [
    'template' => 'welcome',
    'template_vars' => ['name' => 'John'],
    'subject' => 'Welcome',
]);
```

### Custom sender

```php
'from' => ['address' => 'hello@example.com', 'name' => 'My Application'],
```

Formatted as `My Application <hello@example.com>`, or just the address when no name is given.

### Tags

```php
'tags' => ['welcome', 'transactional'],
```

Sent as Mailgun `o:tag` fields. Template-map tags are merged in automatically.

### Attachments

```php
'attachments' => [
    ['fullpath' => '/path/to/file.pdf', 'filename' => 'report.pdf'],
],
```

### Selecting a domain

```php
'domain' => 'transactional', // key into config('mailgun_http.domains')
```

### Rendering a Mailable to HTML

```php
$html = app(MailgunHttpService::class)->renderMailable(new WelcomeMail($user));
```

### Result

`send()` never throws:

```php
['ok' => true,  'status' => 200, 'body' => [...]]      // success
['ok' => false, 'status' => 401, 'error' => '...']     // failure (also logged)
```

## Exception notification

### 1. Enable it

```dotenv
EXCEPTION_EMAIL_ENABLED=true
EXCEPTION_EMAIL_TO=developers@example.com   # single address or comma-separated
```

### 2. Create the Blade view (application-owned)

The package ships **no** view and imposes no design. Create e.g. `resources/views/emails/exception.blade.php`:

```blade
<h1>{{ $Message }}</h1>
<p><strong>{{ $Exception }}</strong> in {{ $File }}:{{ $Line }}</p>
<p>{{ $Method }} {{ $URL }} — {{ $IP }}</p>
<p>{{ $Time }} ({{ $Timezone }})</p>

<h3>Input</h3>
<pre>{{ json_encode($Input, JSON_PRETTY_PRINT) }}</pre>

<h3>Trace</h3>
@foreach ($Trace as $entry)
    <div>{{ $entry['file'] }}:{{ $entry['line'] }} — {{ $entry['class'] }}{{ $entry['class'] ? '::' : '' }}{{ $entry['function'] }}</div>
@endforeach
```

Available data: `Message`, `Exception`, `File`, `Line`, `URL`, `Method`, `IP`, `Input` (sensitive keys redacted), `Timezone`, `Time`, `Trace` (max 15 entries), and `all` (the exception object). In console/queue/scheduler contexts, `URL` is `console` and `Method` is `CLI`.

### 3. Report from your existing exception handling

The package never replaces your exception handler and never produces HTTP responses — your 404/401/422/JSON formatting stays in the application.

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

### Exclusions

Expected exceptions never trigger an email. Defaults (publish the config to customise per application):

```php
'exclude' => [
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Validation\ValidationException::class,
    \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
],
```

### Redaction & security

Request input keys in `mailgun_http.exception.redact` (defaults: `password`, `password_confirmation`, `current_password`, `token`, `api_token`, `access_token`, `secret`) are replaced with `[REDACTED]`, including in nested arrays, before being emailed. The context contains no headers and no configuration values, and Mailgun credentials are never logged.

### Failure safety

If the notification itself fails (Mailgun down, view missing, misconfiguration), the failure is logged as `Exception notification failed: ...` and **never** rethrown — the notifier cannot mask or worsen the original exception.

## Testing & CI

```bash
composer install
vendor/bin/phpunit
```

Tests use Orchestra Testbench + Guzzle's `MockHandler` — no real Mailgun calls or credentials. CI (`.github/workflows/tests.yml`) runs the matrix:

| Laravel | PHP | Testbench |
| ------- | --- | --------- |
| 8       | 8.0 | ^6.0      |
| 12      | 8.3 | ^10.0     |
| 13      | 8.4 | ^11.0     |

New Laravel versions are added as matrix rows once verified.

## License

MIT
