<?php

namespace TheCaretakers\RequestLogger\Providers;

use Illuminate\Support\ServiceProvider;
use TheCaretakers\RequestLogger\Console\Commands\BackupLogsCommand;
use TheCaretakers\RequestLogger\Console\Commands\RotateHttpLogsCommand;
use TheCaretakers\RequestLogger\Http\Middleware\RequestLoggerMiddleware;

class RequestLoggerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/request-logger.php',
            'request-logger'
        );

        // Register a singleton instance of the RequestLoggerMiddleware so we can
        // persist state between the middleware's handle() and terminate() methods.
        $this->app->singleton(RequestLoggerMiddleware::class);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/request-logger.php' => config_path('request-logger.php'),
            ], 'request-logger-config');
        }
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RotateHttpLogsCommand::class,
                BackupLogsCommand::class,
            ]);
        }
    }
}
