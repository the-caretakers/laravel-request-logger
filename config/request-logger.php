<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Master switch to enable or disable request logging.
    */
    'enabled' => env('REQUEST_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disk (for active logging)
    |--------------------------------------------------------------------------
    | Specify the filesystem disk where logs should be actively written.
    | Defaults to 'local'.
    */
    'disk' => env('REQUEST_LOGGER_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Backup Disk (for archival)
    |--------------------------------------------------------------------------
    | Specify the filesystem disk where logs should be transferred for archival.
    | Example: 's3'. Set to null to disable automated transfer.
    */
    'backup_disk' => env('REQUEST_LOGGER_BACKUP_DISK', null),

    /*
    |--------------------------------------------------------------------------
    | Log Profile
    |--------------------------------------------------------------------------
    | Class responsible for determining if a request/response should be logged.
    | Must implement \TheCaretakers\RequestLogger\Contracts\LogProfile::class
    | Leave null to use the default profile (log all requests).
    */
    'log_profile' => \TheCaretakers\RequestLogger\Logging\DefaultLogProfile::class,

    /*
    |--------------------------------------------------------------------------
    | Log Writer
    |--------------------------------------------------------------------------
    | Class responsible for writing the log record.
    | Must implement \TheCaretakers\RequestLogger\Contracts\LogWriter::class
    | Leave null to use the default writer (uses log_channel or disk).
    */
    'log_writer' => \TheCaretakers\RequestLogger\Logging\DefaultLogWriter::class,

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
        'cookie',
        'session',
        'csrf',
        'access_token',
        'refresh_token',
        'client_secret',
        'client_id',
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
    'log_format' => 'json', // Options: 'json', 'line'

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
    'log_response_body' => false,

];
