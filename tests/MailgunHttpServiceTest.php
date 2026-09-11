<?php

namespace Pramesh\LaravelExceptionReporter\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pramesh\LaravelExceptionReporter\MailgunHttpService;

class MailgunHttpServiceTest extends TestCase
{
    /** @var array */
    protected $history = [];

    protected function makeService(array $configOverrides = [], ?array $responses = null, string $environment = 'production'): MailgunHttpService
    {
        $this->history = [];

        $mock = new MockHandler($responses !== null ? $responses : [
            new Response(200, [], json_encode(['id' => '<test@mailgun>', 'message' => 'Queued. Thank you.'])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.mailgun.net/']);

        return new MailgunHttpService($this->mailgunConfig($configOverrides), $client, $environment);
    }

    protected function lastRequestBody(): string
    {
        $this->assertNotEmpty($this->history, 'No Mailgun HTTP request was sent.');

        return (string) $this->history[0]['request']->getBody();
    }

    public function test_it_sends_a_raw_html_email()
    {
        $service = $this->makeService();

        $result = $service->send('user@example.com', [
            'html' => '<h1>Hello</h1>',
            'subject' => 'Hello',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v3/mg.example.test/messages', $request->getUri()->getPath());

        $body = $this->lastRequestBody();
        $this->assertStringContainsString('name="to"', $body);
        $this->assertStringContainsString('user@example.com', $body);
        $this->assertStringContainsString('name="subject"', $body);
        $this->assertStringContainsString('name="html"', $body);
        $this->assertStringContainsString('<h1>Hello</h1>', $body);
    }

    public function test_it_sends_a_stored_template_resolved_through_the_template_map()
    {
        $service = $this->makeService([
            'template_map' => [
                'welcome' => [
                    'template' => 'program_welcome',
                    'tags' => ['program_welcome_email'],
                ],
            ],
        ]);

        $result = $service->send('user@example.com', [
            'template' => 'welcome',
            'template_vars' => ['name' => 'John'],
            'subject' => 'Welcome',
        ]);

        $this->assertTrue($result['ok']);

        $body = $this->lastRequestBody();
        $this->assertStringContainsString('name="template"', $body);
        $this->assertStringContainsString('program_welcome', $body);
        $this->assertStringContainsString('name="h:X-Mailgun-Variables"', $body);
        $this->assertStringContainsString('{"name":"John"}', $body);
        $this->assertStringContainsString('name="o:tag"', $body);
        $this->assertStringContainsString('program_welcome_email', $body);
    }

    public function test_it_formats_multiple_recipients()
    {
        $service = $this->makeService();

        $service->send(['one@example.com', 'two@example.com'], [
            'html' => '<p>Hi</p>',
            'subject' => 'Hi',
        ]);

        $this->assertStringContainsString('one@example.com, two@example.com', $this->lastRequestBody());
    }

    public function test_it_uses_the_default_from_and_supports_overrides()
    {
        $service = $this->makeService();
        $service->send('user@example.com', ['html' => '<p>Hi</p>', 'subject' => 'Hi']);
        $this->assertStringContainsString('Example App <noreply@example.test>', $this->lastRequestBody());

        $service = $this->makeService();
        $service->send('user@example.com', [
            'html' => '<p>Hi</p>',
            'subject' => 'Hi',
            'from' => ['address' => 'hello@example.com', 'name' => 'My Application'],
        ]);
        $this->assertStringContainsString('My Application <hello@example.com>', $this->lastRequestBody());

        $service = $this->makeService();
        $service->send('user@example.com', [
            'html' => '<p>Hi</p>',
            'subject' => 'Hi',
            'from' => ['address' => 'hello@example.com'],
        ]);
        $body = $this->lastRequestBody();
        $this->assertStringContainsString('hello@example.com', $body);
        $this->assertStringNotContainsString('Example App <hello@example.com>', $body);
    }

    public function test_it_sends_tags_for_raw_html_emails()
    {
        $service = $this->makeService();

        $service->send('user@example.com', [
            'html' => '<p>Hi</p>',
            'subject' => 'Welcome',
            'tags' => ['welcome', 'transactional'],
        ]);

        $body = $this->lastRequestBody();
        $this->assertSame(2, substr_count($body, 'name="o:tag"'));
        $this->assertStringContainsString('welcome', $body);
        $this->assertStringContainsString('transactional', $body);
    }

    public function test_it_attaches_files_as_multipart_form_data()
    {
        $path = tempnam(sys_get_temp_dir(), 'mailgun');
        file_put_contents($path, 'PDF-CONTENT');

        $service = $this->makeService();

        $service->send('user@example.com', [
            'html' => '<p>Report attached</p>',
            'subject' => 'Report',
            'attachments' => [
                ['fullpath' => $path, 'filename' => 'report.pdf'],
            ],
        ]);

        $body = $this->lastRequestBody();
        $this->assertStringContainsString('name="attachment"', $body);
        $this->assertStringContainsString('filename="report.pdf"', $body);
        $this->assertStringContainsString('PDF-CONTENT', $body);

        @unlink($path);
    }

    public function test_it_returns_a_predictable_failure_result_when_mailgun_fails()
    {
        $service = $this->makeService([], [
            new Response(401, [], json_encode(['message' => 'Forbidden'])),
        ]);

        $result = $service->send('user@example.com', [
            'html' => '<p>Hi</p>',
            'subject' => 'Hi',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('Forbidden', $result['error']);
    }

    public function test_it_fails_gracefully_when_credentials_are_missing()
    {
        $service = $this->makeService([
            'api_key' => null,
            'api_key_sandbox' => null,
        ]);

        $result = $service->send('user@example.com', ['html' => '<p>Hi</p>', 'subject' => 'Hi']);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertEmpty($this->history);
    }

    public function test_it_selects_a_configured_domain_by_key()
    {
        $service = $this->makeService();

        $service->send('user@example.com', [
            'html' => '<p>Hi</p>',
            'subject' => 'Hi',
            'domain' => 'transactional',
        ]);

        $this->assertSame(
            '/v3/tx.example.test/messages',
            $this->history[0]['request']->getUri()->getPath()
        );
    }

    public function test_it_uses_the_sandbox_key_and_domain_outside_production_environments()
    {
        $service = $this->makeService([
            'api_key_sandbox' => 'sandbox-key',
            'domains' => ['sandbox' => 'sandbox.mailgun.org'],
        ], null, 'local');

        $service->send('user@example.com', ['html' => '<p>Hi</p>', 'subject' => 'Hi']);

        $this->assertSame(
            '/v3/sandbox.mailgun.org/messages',
            $this->history[0]['request']->getUri()->getPath()
        );
    }
}
