<?php

namespace Pramesh\LaravelExceptionReporter;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * General-purpose Mailgun HTTP email service.
 *
 * Sends email directly through the Mailgun HTTP API using Guzzle and is
 * completely independent of the application's Laravel Mail configuration.
 * The exception reporter is only one consumer of this service.
 *
 * Written against PHP 8.0 so the same release line runs on Laravel 8, 12
 * and 13 applications.
 */
class MailgunHttpService
{
    public function __construct(
        protected array $config,
        protected ?ClientInterface $client = null,
        protected string $environment = 'production'
    ) {}

    /**
     * Send an email through the Mailgun HTTP API.
     *
     * Supported options: subject, html, template, template_vars, tags,
     * from (['address' => ..., 'name' => ...]), attachments
     * ([['fullpath' => ..., 'filename' => ...], ...]), domain (key into
     * the configured "domains" map).
     *
     * Never throws; always returns a predictable result:
     *   ['ok' => true,  'status' => 200, 'body' => ...]
     *   ['ok' => false, 'status' => ..., 'error' => ...]
     */
    public function send(string|array $to, array $options = []): array
    {
        try {
            $domain = $this->resolveDomain($options['domain'] ?? 'default');
            $apiKey = $this->apiKey();

            if (empty($apiKey) || empty($domain)) {
                Log::error('Mailgun send skipped: missing API key or sending domain.');

                return [
                    'ok' => false,
                    'status' => 0,
                    'error' => 'Missing Mailgun API key or sending domain.',
                ];
            }

            $response = $this->client()->request(
                'POST',
                $this->version() . '/' . $domain . '/messages',
                ['multipart' => $this->buildPayload($to, $options)]
            );

            $body = (string) $response->getBody();

            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'body' => json_decode($body, true) ?? $body,
            ];
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body = $e->getResponse() !== null ? (string) $e->getResponse()->getBody() : '';

            // Never log credentials; Guzzle messages do not include auth.
            Log::error('Mailgun send failed: ' . $e->getMessage(), ['status' => $status]);

            return [
                'ok' => false,
                'status' => $status,
                'error' => $body !== '' ? $body : $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('Mailgun send failed: ' . $e->getMessage());

            return [
                'ok' => false,
                'status' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Render a Laravel Mailable to an HTML string. The Mailable is only
     * rendered; Laravel Mail is never used to send.
     */
    public function renderMailable(Mailable $mailable): string
    {
        return (string) $mailable->render();
    }

    /**
     * Build the multipart payload for the Mailgun messages endpoint.
     * Multipart handles repeated o:tag fields and attachments uniformly.
     */
    protected function buildPayload(string|array $to, array $options): array
    {
        $fields = [
            ['name' => 'from', 'contents' => $this->formatFrom($options['from'] ?? null)],
            ['name' => 'to', 'contents' => $this->formatRecipients($to)],
            ['name' => 'subject', 'contents' => (string) ($options['subject'] ?? '')],
        ];

        $tags = (array) ($options['tags'] ?? []);

        if (!empty($options['template'])) {
            $map = $this->config['template_map'][$options['template']] ?? [];

            $fields[] = [
                'name' => 'template',
                'contents' => $map['template'] ?? $options['template'],
            ];

            if (!empty($options['template_vars'])) {
                $fields[] = [
                    'name' => 'h:X-Mailgun-Variables',
                    'contents' => json_encode($options['template_vars']),
                ];
            }

            $tags = array_merge($tags, (array) ($map['tags'] ?? []));
        } elseif (isset($options['html'])) {
            $fields[] = ['name' => 'html', 'contents' => (string) $options['html']];
        }

        foreach (array_values(array_unique($tags)) as $tag) {
            $fields[] = ['name' => 'o:tag', 'contents' => (string) $tag];
        }

        foreach ((array) ($options['attachments'] ?? []) as $attachment) {
            if (empty($attachment['fullpath']) || !is_readable($attachment['fullpath'])) {
                continue;
            }

            $fields[] = [
                'name' => 'attachment',
                'contents' => fopen($attachment['fullpath'], 'r'),
                'filename' => $attachment['filename'] ?? basename($attachment['fullpath']),
            ];
        }

        return $fields;
    }

    /**
     * "user@example.com" or ['a@example.com', 'b@example.com']
     * becomes "a@example.com, b@example.com".
     */
    protected function formatRecipients(string|array $to): string
    {
        return implode(', ', array_filter(array_map('trim', (array) $to)));
    }

    /**
     * Format the sender as "Name <address>" or plain "address".
     */
    protected function formatFrom(?array $from = null): string
    {
        $default = (array) ($this->config['from'] ?? []);

        $address = $from['address'] ?? $default['address'] ?? '';
        $name = $from['name'] ?? $default['name'] ?? null;

        // A per-email "from" without a name should not inherit the default name.
        if (!empty($from['address']) && !array_key_exists('name', $from)) {
            $name = null;
        }

        return $name ? $name . ' <' . $address . '>' : (string) $address;
    }

    /**
     * Sandbox credentials are used when the application environment is
     * NOT one of the configured production environments AND both the
     * sandbox API key and sandbox domain are configured.
     */
    protected function usesSandbox(): bool
    {
        $production = (array) ($this->config['production_environments'] ?? ['production', 'staging']);

        if (in_array($this->environment, $production, true)) {
            return false;
        }

        return !empty($this->config['api_key_sandbox'])
            && !empty($this->config['domains']['sandbox']);
    }

    protected function apiKey(): ?string
    {
        return $this->usesSandbox()
            ? $this->config['api_key_sandbox']
            : ($this->config['api_key'] ?? null);
    }

    /**
     * Resolve a domain key ("default", "transactional", ...) to a
     * Mailgun sending domain, falling back to "default".
     */
    protected function resolveDomain(string $key): ?string
    {
        $domains = (array) ($this->config['domains'] ?? []);

        if ($this->usesSandbox()) {
            return $domains['sandbox'];
        }

        return !empty($domains[$key]) ? $domains[$key] : ($domains['default'] ?? null);
    }

    protected function endpoint(): string
    {
        $endpoint = $this->config['endpoint'] ?? 'https://api.mailgun.net';

        // Convenience: MAILGUN_REGION=eu with the default endpoint switches
        // to the EU API without requiring MAILGUN_ENDPOINT to change.
        if (($this->config['region'] ?? 'us') === 'eu' && $endpoint === 'https://api.mailgun.net') {
            $endpoint = 'https://api.eu.mailgun.net';
        }

        return $endpoint;
    }

    protected function version(): string
    {
        return $this->config['version'] ?? 'v3';
    }

    protected function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'base_uri' => rtrim($this->endpoint(), '/') . '/',
            'auth' => ['api', (string) $this->apiKey()],
            'timeout' => $this->config['timeout'] ?? 10,
        ]);
    }
}
