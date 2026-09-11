<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailgun API credentials
    |--------------------------------------------------------------------------
    |
    | The sandbox API key/domain are used automatically when the application
    | environment is NOT one of the "production_environments" below and both
    | sandbox values are configured.
    |
    */

    'api_key' => env('MAILGUN_API_KEY'),

    'api_key_sandbox' => env('MAILGUN_API_KEY_SANDBOX'),

    'region' => env('MAILGUN_REGION', 'us'),

    'endpoint' => env('MAILGUN_ENDPOINT', 'https://api.mailgun.net'),

    'version' => env('MAILGUN_VERSION', 'v3'),

    'timeout' => 10,

    /*
    |--------------------------------------------------------------------------
    | Environments that use the production API key/domain
    |--------------------------------------------------------------------------
    */

    'production_environments' => ['production', 'staging'],

    /*
    |--------------------------------------------------------------------------
    | Default sender
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env('DEFAULT_FROM_EMAIL'),
        'name' => env('DEFAULT_FROM_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailgun sending domains
    |--------------------------------------------------------------------------
    |
    | Emails select a domain by key, e.g. ['domain' => 'transactional'].
    | "default" is used when no domain option is given.
    |
    */

    'domains' => [
        'sandbox' => env('MAILGUN_DOMAIN_SANDBOX'),
        'default' => env('MAILGUN_DOMAIN'),
        'transactional' => env('MAILGUN_DOMAIN_TRANSACTIONAL'),
        'marketing' => env('MAILGUN_DOMAIN_MARKETING'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailgun stored template map
    |--------------------------------------------------------------------------
    |
    | Maps an application template key to a Mailgun stored template name and
    | optional tags, e.g.:
    |
    | 'welcome' => [
    |     'template' => 'program_welcome',
    |     'tags' => ['program_welcome_email'],
    | ],
    |
    */

    'template_map' => [
        // Application-specific Mailgun stored templates.
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception notification
    |--------------------------------------------------------------------------
    */

    'exception' => [

        'enabled' => env('EXCEPTION_EMAIL_ENABLED', false),

        'to' => env('EXCEPTION_EMAIL_TO'),

        'subject' => env('EXCEPTION_EMAIL_SUBJECT', 'Laravel Application Exception'),

        // Key into the "domains" map above.
        'domain' => env('EXCEPTION_EMAIL_DOMAIN', 'default'),

        // Application-owned Blade view. The package does NOT ship a view.
        'view' => env('EXCEPTION_EMAIL_VIEW', 'emails.exception'),

        // Defaults to config('app.timezone') when null.
        'timezone' => env('EXCEPTION_EMAIL_TIMEZONE'),

        // Maximum number of stack trace entries included in the email.
        'trace_limit' => 15,

        // Exceptions that never trigger a notification email.
        'exclude' => [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        ],

        // Request input keys that are redacted before being emailed.
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

];
