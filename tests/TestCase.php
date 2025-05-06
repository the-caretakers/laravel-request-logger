<?php

namespace TheCaretakers\RequestLogger\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use TheCaretakers\RequestLogger\Providers\RequestLoggerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up a fake testing disk
        Storage::fake('testing_disk');

        // You might want to configure the package during tests
        config()->set('request-logger.disk', 'testing_disk');
        config()->set('request-logger.log_path_structure', 'http-logs/{Y}-{m}-{d}.log');
        config()->set('request-logger.log_request_body', true);
        config()->set('request-logger.log_response_body', true);
        config()->set('request-logger.log_format', 'json');
        config()->set('request-logger.sensitive_keywords', ['password', 'secret']);
        config()->set('request-logger.truncate_limit', 1000);
        config()->set('request-logger.log_channel', null);
        config()->set('request-logger.log_profile', null);
        config()->set('request-logger.log_writer', null);
    }

    protected function tearDown(): void
    {
        if ($this->app && method_exists($this->app, 'terminate')) {
            $this->app->terminate(); // Terminate app before parent cleanup
        }
        parent::tearDown();
    }

    /**
     * Load package service provider
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            RequestLoggerServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Define a test route
        $app['router']->get('/test-route', function () {
            return response()->json(['message' => 'Test response', 'secret' => '12345']);
        });

        $app['router']->post('/test-post-route', function () {
            return response()->json(['message' => 'Post successful']);
        });

        // Apply the middleware globally for testing purposes (Reverted to this)
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->pushMiddleware(\TheCaretakers\RequestLogger\Http\Middleware\RequestLoggerMiddleware::class);
    }
}
