<?php

use Illuminate\Support\Facades\Storage;
use TheCaretakers\RequestLogger\Tests\TestClasses\CustomLogProfile;
use TheCaretakers\RequestLogger\Tests\TestClasses\CustomLogWriter;

// Helper function to get log content
function getLogContent(string $disk = 'testing_disk', ?string $path = null): ?array
{
    $date = now()->format('Y-m-d');
    $logPath = $path ?? "http-logs/{$date}.log"; // Use provided path or default

    // Check existence without failing the test here, let the main test assertion handle it.
    if (! Storage::disk($disk)->exists($logPath)) {
        return null;
    }

    $content = Storage::disk($disk)->get($logPath);
    if (! $content) {
        return null;
    }
    // Logs are appended, get the last entry (assuming JSONL format)
    $lines = explode(PHP_EOL, trim($content));
    $lastLine = end($lines);

    return json_decode($lastLine, true);
}

it('logs basic request and response details', function () {
    $response = $this->get('/test-route');
    $this->app->terminate(); // Terminate after request

    $response->assertOk();
    $response->assertJson(['message' => 'Test response']);

    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();
    expect($logData['request']['method'])->toBe('GET');
    expect($logData['request']['uri'])->toBe('/test-route');
    expect($logData['request']['ip'])->toBe('127.0.0.1');
    expect($logData['response']['status_code'])->toBe(200);
    expect($logData['response']['body']['message'])->toBe('Test response');
});

it('sanitizes sensitive data in request and response', function () {
    config()->set('request-logger.sensitive_keywords', ['password', 'secret', 'x-api-key']);

    $response = $this->post('/test-post-route', [
        'username' => 'testuser',
        'password' => 'supersecretpassword',
    ], [
        'Authorization' => 'Bearer sometoken', // Should be sanitized by default
        'X-API-Key'     => 'abcdef123456', // Added to sensitive keywords
    ]);
    $this->app->terminate(); // Terminate after request

    $response->assertOk();

    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    // Check request sanitization
    expect($logData['request']['body']['password'])->toBe('[SANITIZED]');
    expect($logData['request']['body']['username'])->toBe('testuser');
    expect($logData['request']['headers']['authorization'][0])->toBe('[SANITIZED]');
    expect($logData['request']['headers']['x-api-key'][0])->toBe('[SANITIZED]');

    // Check response sanitization (using the default response from TestCase)
    $this->get('/test-route'); // Make a request whose response has sensitive data
    $this->app->terminate(); // Terminate after second request
    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    expect($logData['response']['body']['secret'])->toBe('[SANITIZED]');
    expect($logData['response']['body']['message'])->toBe('Test response');
});

it('can disable logging request body', function () {
    config()->set('request-logger.log_request_body', false);

    $this->post('/test-post-route', ['data' => 'somedata']);
    $this->app->terminate(); // Terminate after request

    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    expect($logData['request']['body'])->toBe('[NOT LOGGED]');
});

it('can disable logging response body', function () {
    config()->set('request-logger.log_response_body', false);

    $this->get('/test-route');
    $this->app->terminate(); // Terminate after request

    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    expect($logData['response']['body'])->toBe('[NOT LOGGED]');
});

it('uses log profile to determine if request should be logged', function () {
    // Configure a profile that only logs POST requests
    config()->set('request-logger.log_profile', CustomLogProfile::class);

    // Make a GET request - should NOT be logged
    $this->get('/test-route');
    $this->app->terminate(); // Terminate after request
    $logPath = 'http-logs/'.now()->format('Y-m-d').'.log';
    Storage::disk('testing_disk')->assertMissing($logPath); // Assert file doesn't exist yet

    // Make a POST request - SHOULD be logged
    $this->post('/test-post-route', ['data' => 'logthis']);
    $this->app->terminate(); // Terminate after request
    Storage::disk('testing_disk')->assertExists($logPath);

    $logData = getLogContent();

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    expect($logData['request']['method'])->toBe('POST');
});

it('uses custom log writer when configured', function () {
    // Configure a custom writer that writes to a different disk/path
    config()->set('request-logger.log_writer', CustomLogWriter::class);
    Storage::fake('custom_disk'); // Fake the disk used by the custom writer

    $this->get('/test-route');
    $this->app->terminate(); // Terminate after request

    // Assert log exists on the custom disk/path, not the default one
    Storage::disk('testing_disk')->assertMissing('http-logs/'.now()->format('Y-m-d').'.log');
    Storage::disk('custom_disk')->assertExists('custom-path/custom.log');

    // Check content written by custom writer
    $logData = getLogContent('custom_disk', 'custom-path/custom.log');

    expect($logData)->not->toBeNull();
    expect($logData)->toBeArray();

    expect($logData['message'])->toBe('Logged by CustomLogWriter');
    expect($logData['data']['request']['method'])->toBe('GET');
});

it('logs via log channel when configured', function () {
    // Configure logging channel
    config()->set('logging.channels.requestlog_test', [
        'driver' => 'single',
        'path'   => storage_path('logs/requests.log'),
        'level'  => 'info',
    ]);
    config()->set('request-logger.log_channel', 'requestlog_test');
    config()->set('request-logger.disk', null); // Ensure disk is not used

    // Mock the Log facade
    \Illuminate\Support\Facades\Log::shouldReceive('channel')
        ->with('requestlog_test')
        ->once()
        ->andReturnSelf() // Return the mock itself for chaining
        ->shouldReceive('info')
        ->with(
            Mockery::on(function ($message) {
                return $message === 'HTTP Request Log'; // Check the message string
            }),
            Mockery::on(function ($context) { // Check the context array
                return is_array($context)
                    && isset($context['request']['method']) && $context['request']['method'] === 'GET'
                    && isset($context['response']['status_code']) && $context['response']['status_code'] === 200;
            })
        )
        ->once();

    $this->get('/test-route');
    $this->app->terminate(); // Terminate after request

    // Assertion is handled by the mock expectation
});
