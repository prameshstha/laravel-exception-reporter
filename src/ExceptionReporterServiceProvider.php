<?php

namespace Pramesh\LaravelExceptionReporter;

use Illuminate\Support\ServiceProvider;

class ExceptionReporterServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/exception_reporter.php', 'exception_reporter');

        $this->app->singleton(MailgunHttpService::class, function ($app) {
            return new MailgunHttpService(
                (array) $app['config']->get('exception_reporter', []),
                null,
                $app->environment()
            );
        });

        $this->app->singleton(ExceptionReporter::class, function ($app) {
            return new ExceptionReporter($app->make(MailgunHttpService::class));
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/exception_reporter.php' => config_path('exception_reporter.php'),
            ], 'exception-reporter-config');
        }
    }
}
