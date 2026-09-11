<?php

namespace Pramesh\LaravelExceptionReporter\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Pramesh\LaravelExceptionReporter\ExceptionReporterServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [ExceptionReporterServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Blade fixtures used by the exception notification tests.
        $app['config']->set('view.paths', array_merge(
            [__DIR__ . '/fixtures/views'],
            (array) $app['config']->get('view.paths', [])
        ));

        $app['config']->set('app.timezone', 'UTC');

        // Baseline Mailgun configuration for tests (no real credentials).
        $app['config']->set('exception_reporter.api_key', 'test-key');
        $app['config']->set('exception_reporter.domains.default', 'mg.example.test');
        $app['config']->set('exception_reporter.domains.transactional', 'tx.example.test');
        $app['config']->set('exception_reporter.from', [
            'address' => 'noreply@example.test',
            'name' => 'Example App',
        ]);
    }

    /**
     * The Mailgun config array as the service provider would build it,
     * with optional overrides.
     */
    protected function mailgunConfig(array $overrides = []): array
    {
        return array_replace_recursive(
            (array) $this->app['config']->get('exception_reporter', []),
            $overrides
        );
    }
}
