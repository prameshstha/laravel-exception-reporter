<?php

namespace Pramesh\LaravelExceptionReporter\Tests;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Pramesh\LaravelExceptionReporter\ExceptionReporter;
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

class SpyMailgunService extends MailgunHttpService
{
    /** @var array */
    public $sent = [];

    /** @var array */
    public $result = ['ok' => true, 'status' => 200, 'body' => []];

    /** @var bool */
    public $shouldThrow = false;

    public function __construct()
    {
        parent::__construct([], null, 'testing');
    }

    public function send(string|array $to, array $options = []): array
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('Mailgun exploded');
        }

        $this->sent[] = ['to' => $to, 'options' => $options];

        return $this->result;
    }
}

class ExceptionReporterTest extends TestCase
{
    /** @var SpyMailgunService */
    protected $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new SpyMailgunService();
        $this->app->instance(MailgunHttpService::class, $this->mailer);

        config([
            'exception_reporter.exception.enabled' => true,
            'exception_reporter.exception.to' => 'developers@example.test',
            'exception_reporter.exception.view' => 'emails.exception',
        ]);
    }

    protected function handler(): ExceptionReporter
    {
        return $this->app->make(ExceptionReporter::class);
    }

    public function test_it_sends_an_exception_email_rendered_from_the_application_view()
    {
        $this->handler()->report(new RuntimeException('Something broke'));

        $this->assertCount(1, $this->mailer->sent);

        $sent = $this->mailer->sent[0];
        $this->assertSame(['developers@example.test'], $sent['to']);
        $this->assertSame('Laravel Application Exception', $sent['options']['subject']);
        $this->assertSame('default', $sent['options']['domain']);
        $this->assertStringContainsString('Something broke', $sent['options']['html']);
        $this->assertStringContainsString(RuntimeException::class, $sent['options']['html']);
    }

    public function test_it_does_nothing_when_disabled()
    {
        config(['exception_reporter.exception.enabled' => false]);

        $this->handler()->report(new RuntimeException('Something broke'));

        $this->assertCount(0, $this->mailer->sent);
    }

    public function test_it_ignores_excluded_exceptions()
    {
        $this->handler()->report(new AuthenticationException());

        $this->assertCount(0, $this->mailer->sent);
    }

    public function test_it_supports_comma_separated_recipients()
    {
        config(['exception_reporter.exception.to' => 'a@example.test, b@example.test']);

        $this->handler()->report(new RuntimeException('Boom'));

        $this->assertSame(['a@example.test', 'b@example.test'], $this->mailer->sent[0]['to']);
    }

    public function test_it_passes_the_configured_domain_and_subject()
    {
        config([
            'exception_reporter.exception.domain' => 'transactional',
            'exception_reporter.exception.subject' => 'API Exception',
        ]);

        $this->handler()->report(new RuntimeException('Boom'));

        $this->assertSame('transactional', $this->mailer->sent[0]['options']['domain']);
        $this->assertSame('API Exception', $this->mailer->sent[0]['options']['subject']);
    }

    public function test_it_builds_a_useful_context_with_cli_defaults()
    {
        $exception = new RuntimeException('Context please');

        $context = $this->handler()->context($exception);

        $this->assertSame('Context please', $context['Message']);
        $this->assertSame(RuntimeException::class, $context['Exception']);
        $this->assertSame(__FILE__, $context['File']);
        $this->assertIsInt($context['Line']);
        $this->assertSame('console', $context['URL']);
        $this->assertSame('CLI', $context['Method']);
        $this->assertNull($context['IP']);
        $this->assertSame([], $context['Input']);
        $this->assertSame('UTC', $context['Timezone']);
        $this->assertNotEmpty($context['Time']);
        $this->assertSame($exception, $context['all']);

        $this->assertNotEmpty($context['Trace']);
        $this->assertLessThanOrEqual(15, count($context['Trace']));
        $this->assertArrayHasKey('file', $context['Trace'][0]);
        $this->assertArrayHasKey('line', $context['Trace'][0]);
        $this->assertArrayHasKey('function', $context['Trace'][0]);
        $this->assertArrayHasKey('class', $context['Trace'][0]);
    }

    public function test_it_redacts_sensitive_request_input()
    {
        $handler = new class($this->mailer) extends ExceptionReporter {
            public function redact(array $input, array $keys): array
            {
                return $this->sanitizeInput($input, $keys);
            }
        };

        $sanitized = $handler->redact([
            'email' => 'user@example.test',
            'password' => 'super-secret',
            'nested' => ['api_token' => 'abc123', 'ok' => 'value'],
        ], (array) config('exception_reporter.exception.redact'));

        $this->assertSame('user@example.test', $sanitized['email']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['api_token']);
        $this->assertSame('value', $sanitized['nested']['ok']);
    }

    public function test_a_notification_failure_never_throws_a_second_exception()
    {
        Log::shouldReceive('error')->once()->withArgs(function ($message) {
            return strpos($message, 'Exception notification failed') === 0;
        });

        $this->mailer->shouldThrow = true;

        $this->handler()->report(new RuntimeException('Original exception'));

        // Reaching this line means report() swallowed the mailer failure.
        $this->assertTrue(true);
    }

    public function test_a_mailgun_rejection_is_logged_but_not_thrown()
    {
        Log::shouldReceive('error')->once();

        $this->mailer->result = ['ok' => false, 'status' => 500, 'error' => 'boom'];

        $this->handler()->report(new RuntimeException('Original exception'));

        $this->assertCount(1, $this->mailer->sent);
    }
}
