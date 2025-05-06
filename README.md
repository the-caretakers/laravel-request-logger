# Laravel Request Logger

[![Latest Version on Packagist](https://img.shields.io/packagist/v/the-caretakers/laravel-request-logger.svg?style=flat-square)](https://packagist.org/packages/the-caretakers/laravel-request-logger)
[![Total Downloads](https://img.shields.io/packagist/dt/the-caretakers/laravel-request-logger.svg?style=flat-square)](https://packagist.org/packages/the-caretakers/laravel-request-logger)

Log incoming HTTP requests and their corresponding responses within your Laravel application. This package provides middleware to capture request/response details, sanitize sensitive data, and store logs on a configurable filesystem disk (like local or S3). Includes a command for log rotation to manage retention for disk space.

## Installation

You can install the package via composer:

```bash
composer require the-caretakers/laravel-request-logger
```

## Configuration

Publish the configuration file using the `vendor:publish` Artisan command:

```bash
php artisan vendor:publish --provider="TheCaretakers\RequestLogger\Providers\RequestLoggerServiceProvider" --tag="request-logger-config"
```

This will create a `config/request-logger.php` file. Review the configuration options:

*   `disk`: Filesystem disk for storing logs (defaults to `config('filesystems.default')`). Can be set via `REQUEST_LOGGER_DISK` env variable.
*   `log_profile`: (Optional) Class to determine if a request should be logged.
*   `log_writer`: (Optional) Class to handle writing the log entry.
*   `sensitive_keywords`: Array of keys whose values will be sanitized in logs.
*   `truncate_limit`: Max length for logged string values before truncation.
*   `log_path_structure`: Path format for log files (e.g., `http-logs/{Y}-{m}-{d}.log`).
*   `log_format`: Log entry format (`json` recommended).
*   `log_channel`: (Optional) Log via a specific Laravel log channel instead of direct filesystem access.
*   `log_request_body`: Boolean to enable/disable logging request body.
*   `log_response_body`: Boolean to enable/disable logging response body.

**Important:** Ensure the configured filesystem disk (e.g., `s3`) is properly set up in your `config/filesystems.php`.

## Usage

### Middleware Registration

#### Laravel 10 and below (`app/Http/Kernel.php`)

Add the `RequestLoggerMiddleware` to the desired middleware group(s) in your `app/Http/Kernel.php`:

**Web Routes:**

```php
protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \TheCaretakers\RequestLogger\Http\Middleware\RequestLoggerMiddleware::class,
    ],
    // ...
];
```

**API Routes:**

```php
protected $middlewareGroups = [
    // ...
    'api' => [
        // ... other middleware
        \TheCaretakers\RequestLogger\Http\Middleware\RequestLoggerMiddleware::class,
    ],
];
```

Or apply it to specific routes or route groups.

#### Laravel 11+ (`bootstrap/app.php`)

In Laravel 11 and later, middleware registration is typically done in the `bootstrap/app.php` file. Use the `withMiddleware` method:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Add the request logger middleware globally
        $middleware->append(\TheCaretakers\RequestLogger\Http\Middleware\RequestLoggerMiddleware::class);

        // You can also apply it conditionally or to specific groups if needed
        // $middleware->web(...)
        // $middleware->api(...)
    })
    ->create();
```

### Log Rotation

The package includes an Artisan command to delete old log files.

```bash
php artisan request-logger:rotate
```

**Options:**

*   `--days=30`: Specify the number of days of logs to keep (default: 30).
*   `--disk=s3`: Override the configured disk for this run.
*   `--dry-run`: Simulate the rotation without actually deleting files.

You should schedule this command to run periodically (e.g., daily) in your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // ...
    $schedule->command('request-logger:rotate --days=60')->daily(); // Keep 60 days of logs
}
```

## Customization (Advanced)

*   **Log Profile:** Create a class implementing `TheCaretakers\RequestLogger\Contracts\LogProfile` with a `shouldLog(Request $request): bool` method. Register it in the `log_profile` config key.
*   **Log Writer:** Create a class implementing `TheCaretakers\RequestLogger\Contracts\LogWriter` with a `write(array $logData): void` method. Register it in the `log_writer` config key.

## Security Vulnerabilities

Please report any security vulnerabilities to [The Caretakers](mailto:dev@caretakers.io). Your discretion is appreciated.
Please do not use the issue tracker for security vulnerabilities.

## Credits

-   [The Caretakers](https://github.com/the-caretakers)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT).
