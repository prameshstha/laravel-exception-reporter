<?php

namespace Pramesh\LaravelExceptionReporter;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Global exception notifier.
 *
 * Renders an application-owned Blade view with exception context and
 * emails the resulting HTML through MailgunHttpService.
 *
 * This class never generates HTTP responses (those remain application
 * code) and never throws: any failure while notifying is logged and
 * swallowed so the original exception is never masked or made worse.
 */
class ExceptionReporter
{
    public function __construct(protected MailgunHttpService $mailer) {}

    /**
     * Report an exception: if notifications are enabled and the exception
     * is not excluded, render the configured view and send it via Mailgun.
     */
    public function report(Throwable $exception): void
    {
        try {
            $config = (array) config('exception_reporter.exception', []);

            if (empty($config['enabled'])) {
                return;
            }

            if ($this->shouldIgnore($exception, (array) ($config['exclude'] ?? []))) {
                return;
            }

            $to = $this->recipients($config['to'] ?? null);

            if (empty($to)) {
                Log::warning('Exception notification skipped: EXCEPTION_EMAIL_TO is not configured.');

                return;
            }

            $html = view($config['view'] ?? 'emails.exception', $this->context($exception))->render();

            $result = $this->mailer->send($to, [
                'subject' => $config['subject'] ?? 'Laravel Application Exception',
                'html' => $html,
                'domain' => $config['domain'] ?? 'default',
            ]);

            if (empty($result['ok'])) {
                Log::error('Exception notification failed: Mailgun did not accept the message.', [
                    'status' => $result['status'] ?? null,
                ]);
            }
        } catch (Throwable $notifierException) {
            // Critical: never let the notifier throw a second exception.
            Log::error('Exception notification failed: ' . $notifierException->getMessage());
        }
    }

    /**
     * Build the context array passed to the application's Blade view.
     */
    public function context(Throwable $exception): array
    {
        $config = (array) config('exception_reporter.exception', []);

        $timezone = $config['timezone'] ?? null;
        $timezone = $timezone ?: config('app.timezone', 'UTC');

        $request = $this->requestContext($config);

        return [
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
        ];
    }

    protected function shouldIgnore(Throwable $exception, array $exclude): bool
    {
        foreach ($exclude as $excluded) {
            if ($exception instanceof $excluded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise the configured recipient(s): a single address, a
     * comma-separated string, or an array.
     */
    protected function recipients(string|array|null $to): array
    {
        if (is_string($to)) {
            $to = explode(',', $to);
        }

        return array_values(array_filter(array_map('trim', (array) $to)));
    }

    /**
     * Collect request details when a real HTTP request exists; otherwise
     * fall back to CLI values. Exceptions may be reported from HTTP
     * requests, queues, scheduled jobs, or console commands, so a request
     * is never assumed.
     */
    protected function requestContext(array $config): array
    {
        $context = [
            'url' => 'console',
            'method' => 'CLI',
            'ip' => null,
            'input' => [],
        ];

        try {
            if (app()->runningInConsole() || !app()->bound('request')) {
                return $context;
            }

            $request = app('request');

            $context['url'] = $request->fullUrl();
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();
            $context['input'] = $this->sanitizeInput(
                (array) $request->input(),
                (array) ($config['redact'] ?? [])
            );
        } catch (Throwable) {
            // Fall back to the CLI defaults rather than failing.
        }

        return $context;
    }

    /**
     * Redact sensitive keys (recursively) from the request input before
     * it is placed in an email.
     */
    protected function sanitizeInput(array $input, array $redact): array
    {
        foreach ($input as $key => $value) {
            if (in_array((string) $key, $redact, true)) {
                $input[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $input[$key] = $this->sanitizeInput($value, $redact);
            }
        }

        return $input;
    }

    /**
     * A limited, email-friendly stack trace.
     */
    protected function trace(Throwable $exception, int $limit): array
    {
        $trace = [];

        foreach (array_slice($exception->getTrace(), 0, max(1, $limit)) as $entry) {
            $trace[] = [
                'file' => $entry['file'] ?? '[internal]',
                'line' => $entry['line'] ?? null,
                'function' => $entry['function'] ?? null,
                'class' => $entry['class'] ?? null,
            ];
        }

        return $trace;
    }
}
