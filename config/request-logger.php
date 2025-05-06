<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Filesystem Disk
    |--------------------------------------------------------------------------
    | Specify the filesystem disk where logs should be stored.
    | Uses the default filesystem disk if set to null.
    */
    'disk' => env('REQUEST_LOGGER_DISK', config('filesystems.default')),

    /*
    |--------------------------------------------------------------------------
    | Log Profile
    |--------------------------------------------------------------------------
    | Class responsible for determining if a request/response should be logged.
    | Must implement \TheCaretakers\RequestLogger\Contracts\LogProfile::class (adjust namespace if needed)
    | Leave null to log all requests.
    */
    'log_profile' => null, // Example: \App\Logging\MyLogProfile::class,

    /*
    |--------------------------------------------------------------------------
    | Log Writer
    |--------------------------------------------------------------------------
    | Class responsible for writing the log record.
    | Must implement \TheCaretakers\RequestLogger\Contracts\LogWriter::class (adjust namespace if needed)
    | Leave null to use the default writer.
    */
    'log_writer' => null, // Example: \App\Logging\MyLogWriter::class,

    /*
    |--------------------------------------------------------------------------
    | Sensitive Keywords
    |--------------------------------------------------------------------------
    | List of keywords (case-insensitive) whose values will be replaced
    | with "[SANITIZED]" in logged request headers and body data.
    | Applied recursively to arrays.
    */
    'sensitive_keywords' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'key',
        'authorization',
        'php-auth-pw',
        'x-api-key',
        'x-csrf-token',
        'x-xsrf-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Truncate Limit
    |--------------------------------------------------------------------------
    | Maximum character length for logged header and body values.
    | Longer values will be truncated. Set to null or 0 to disable truncation.
    */
    'truncate_limit' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Log File Path Structure
    |--------------------------------------------------------------------------
    | Define the path structure for log files within the chosen disk.
    | Use placeholders like {Y}, {m}, {d}, {H}.
    | Example: 'http-logs/{Y}/{m}/{d}.log' for daily log files.
    | Example: 'http-logs/{Y}-{m}-{d}/{uuid}.json' for individual request files.
    */
    'log_path_structure' => 'http-logs/{Y}-{m}-{d}.log',

    /*
    |--------------------------------------------------------------------------
    | Log Format
    |--------------------------------------------------------------------------
    | Choose the format for log entries. 'json' is recommended.
    */
    'log_format' => 'json', // Options: 'json', 'line' (future)

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    | Optionally, specify a Laravel log channel to send logs to instead of
    | using the filesystem disk directly. If set, 'disk' and 'log_path_structure'
    | might be ignored depending on the channel's configuration.
    */
    'log_channel' => null, // Example: 'stack', 'single'

    /*
    |--------------------------------------------------------------------------
    | Log Request Body
    |--------------------------------------------------------------------------
    | Determine if the request body should be logged.
    */
    'log_request_body' => true,

    /*
    |--------------------------------------------------------------------------
    | Log Response Body
    |--------------------------------------------------------------------------
    | Determine if the response body should be logged. Be cautious with large responses.
    */
    'log_response_body' => false, // Default to false

];
