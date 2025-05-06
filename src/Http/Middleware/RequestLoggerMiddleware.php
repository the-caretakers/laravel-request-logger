<?php

namespace TheCaretakers\RequestLogger\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TheCaretakers\RequestLogger\Contracts\LogProfile;
use TheCaretakers\RequestLogger\Contracts\LogWriter;
use Throwable;

class RequestLoggerMiddleware
{
    protected array $logData = [];

    protected ?DateTimeImmutable $startTime = null;

    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * Create a new middleware instance.
     *
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Decide whether to log this request *before* doing anything else
        if (! $this->shouldLog($request)) {
            return $next($request);
        }

        $this->startTime = new DateTimeImmutable;

        // Basic request data - more details in terminate()
        $this->logData['request'] = [
            'start_time' => $this->startTime->format('Y-m-d H:i:s.u P'), // ISO 8601 with microseconds
            'method'     => $request->getMethod(),
            'uri'        => $request->getRequestUri(),
            'url'        => $request->getUri(),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            // Headers and body will be processed in terminate for sanitization
        ];

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response  // Use base Response for broader compatibility
     */
    public function terminate(Request $request, $response): void
    {
        // If startTime is null, it means shouldLog returned false in handle()
        if ($this->startTime === null) {
            return;
        }

        try {
            $endTime = new DateTimeImmutable;
            $duration = $this->startTime ? $endTime->diff($this->startTime) : null;

            // Request Details
            $this->logData['request']['headers'] = $this->sanitize($request->headers->all(), config('request-logger.sensitive_keywords', []));

            if (config('request-logger.log_request_body', true)) {
                // Handle different content types appropriately
                $contentType = $request->header('Content-Type');
                if (Str::contains($contentType, ['/json', '+json'])) {
                    $body = $request->json()->all(); // Get as array
                } elseif ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
                    $body = $request->input(); // Get form data
                    // Note: File uploads are not directly in input(), handle separately if needed
                } else {
                    $body = []; // Or $request->getContent() if raw body needed, but sanitize carefully
                }
                $this->logData['request']['body'] = $this->sanitize($body, config('request-logger.sensitive_keywords', []));
            } else {
                $this->logData['request']['body'] = '[NOT LOGGED]';
            }

            // Response Details
            $this->logData['response'] = [
                'end_time'    => $endTime->format('Y-m-d H:i:s.u P'),
                'duration_ms' => $duration ? ($duration->s * 1000 + $duration->f / 1000) : null,
                'status_code' => $response->getStatusCode(),
                'status_text' => Response::$statusTexts[$response->getStatusCode()] ?? 'Unknown',
                'headers'     => $this->sanitize($response->headers->all(), config('request-logger.sensitive_keywords', [])),
            ];

            if (config('request-logger.log_response_body', true)) {
                // Handle different content types appropriately
                $contentType = $response->headers->get('Content-Type');
                if (Str::contains($contentType, ['/json', '+json'])) {
                    $responseBody = json_decode($response->getContent(), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $responseBody = '[NON-JSON BODY] '.Str::limit($response->getContent(), config('request-logger.truncate_limit', 1000));
                    }
                } elseif (Str::contains($contentType, ['text/', 'html', 'xml'])) {
                    $responseBody = Str::limit($response->getContent(), config('request-logger.truncate_limit', 1000));
                } else {
                    $responseBody = '[BINARY OR UNSUPPORTED BODY]'; // Avoid logging large binary data
                }
                $this->logData['response']['body'] = $this->sanitize($responseBody, config('request-logger.sensitive_keywords', [])); // Sanitize just in case
            } else {
                $this->logData['response']['body'] = '[NOT LOGGED]';
            }

            $this->writeLog();
        } catch (Throwable $e) {
            // Log errors during the logging process itself to the default Laravel log
            Log::error('Error in RequestLoggerMiddleware terminate: '.$e->getMessage(), [
                'exception'   => $e,
                'request_uri' => $request->getRequestUri(),
            ]);
        }
    }

    /**
     * Sanitizes an array recursively based on keywords and truncation limit.
     */
    protected function sanitize(mixed $data, array $sensitiveKeywords): mixed
    {
        $truncateLimit = config('request-logger.truncate_limit');

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $lowerKey = strtolower($key);
                $isSensitive = false;
                foreach ($sensitiveKeywords as $keyword) {
                    if (str_contains($lowerKey, strtolower($keyword))) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive) {
                    $sanitized[$key] = '[SANITIZED]';
                } else {
                    $sanitized[$key] = $this->sanitize($value, $sensitiveKeywords); // Recurse
                }
            }

            return $sanitized;
        } elseif (is_string($data)) {
            if ($truncateLimit > 0 && mb_strlen($data) > $truncateLimit) {
                return mb_substr($data, 0, $truncateLimit).'... [TRUNCATED]';
            }

            return $data;
        } elseif (is_object($data)) {
            // Attempt to convert object to array, might not work for all objects
            return $this->sanitize((array) $data, $sensitiveKeywords);
        }

        // Return other types (int, bool, null) as is
        return $data;
    }

    /**
     * Writes the collected log data using configured writer or default methods.
     */
    protected function writeLog(): void
    {
        $logWriterClass = config('request-logger.log_writer');

        if ($logWriterClass) {
            try {
                if (! class_exists($logWriterClass)) {
                    Log::warning("RequestLoggerMiddleware: LogWriter class '{$logWriterClass}' not found in config. Falling back to default writer.");
                } else {
                    $logWriter = $this->app->make($logWriterClass);

                    if (! $logWriter instanceof LogWriter) {
                        Log::warning("RequestLoggerMiddleware: Configured LogWriter class '{$logWriterClass}' does not implement TheCaretakers\\RequestLogger\\Contracts\\LogWriter. Falling back to default writer.");
                    } elseif (! method_exists($logWriter, 'write')) {
                        Log::warning("RequestLoggerMiddleware: LogWriter class '{$logWriterClass}' does not have a 'write' method. Falling back to default writer.");
                    } else {
                        // Use the custom writer
                        $logWriter->write($this->logData);

                        return; // Log written by custom writer
                    }
                }
            } catch (Throwable $e) {
                Log::error("RequestLoggerMiddleware: Error while executing LogWriter '{$logWriterClass}'. Falling back to default writer.", [
                    'exception' => $e,
                ]);
            }
        }

        // Default Log Writing Logic (if no custom writer or custom writer failed)
        $logChannel = config('request-logger.log_channel');
        $logFormat = config('request-logger.log_format', 'json');

        if ($logChannel) {
            // Log via Laravel's logging system (context is automatically handled)
            Log::channel($logChannel)->info('HTTP Request Log', $this->logData);
        } else {
            // Log directly to filesystem
            $diskName = config('request-logger.disk');
            if (! $diskName) {
                Log::warning('RequestLoggerMiddleware: Filesystem disk not configured.');

                return;
            }

            $pathTemplate = config('request-logger.log_path_structure', 'http-logs/{Y}-{m}-{d}.log');
            $filePath = $this->generateFilePath($pathTemplate);

            try {
                $disk = Storage::disk($diskName);

                if ($logFormat === 'json') {
                    $logLine = json_encode($this->logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
                } else {
                    // Basic line format (can be expanded)
                    $req = $this->logData['request'];
                    $res = $this->logData['response'];
                    $logLine = sprintf(
                        "[%s] %s %s - %d %s (%dms)\n",
                        $req['start_time'],
                        $req['method'],
                        $req['uri'],
                        $res['status_code'],
                        $res['status_text'],
                        $res['duration_ms'] ?? 0
                    );
                }

                // Use append for log files
                if (Str::endsWith($pathTemplate, ['.log', '.jsonl', '.txt'])) {
                    $disk->append($filePath, $logLine);
                } else {
                    // Assume individual file per request (e.g., .json)
                    $disk->put($filePath, $logLine);
                }

            } catch (Throwable $e) {
                Log::error('RequestLoggerMiddleware: Failed to write log to disk.', [
                    'disk'      => $diskName,
                    'path'      => $filePath,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Generates the log file path based on the configured structure.
     */
    protected function generateFilePath(string $pathTemplate): string
    {
        $now = $this->startTime ?? new DateTimeImmutable; // Use start time for consistency
        $replacements = [
            '{Y}'    => $now->format('Y'),
            '{m}'    => $now->format('m'),
            '{d}'    => $now->format('d'),
            '{H}'    => $now->format('H'),
            '{uuid}' => Str::uuid()->toString(), // For unique file names if needed
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pathTemplate);
    }

    /**
     * Determines if the current request should be logged based on the LogProfile.
     */
    protected function shouldLog(Request $request): bool
    {
        $logProfileClass = config('request-logger.log_profile');

        // If no profile is specified, log everything
        if (! $logProfileClass) {
            return true;
        }

        // Check if the configured profile class exists
        if (! class_exists($logProfileClass)) {
            Log::warning("RequestLoggerMiddleware: LogProfile class '{$logProfileClass}' not found in config. Logging request.", [
                'request_uri' => $request->getRequestUri(),
            ]);

            return true; // Log if profile class is missing to be safe
        }

        try {
            // Resolve the profile from the container
            $logProfile = app($logProfileClass);

            // Check if it implements the contract
            if (! $logProfile instanceof LogProfile) {
                Log::warning("RequestLoggerMiddleware: Configured LogProfile class '{$logProfileClass}' does not implement the TheCaretakers\\RequestLogger\\Contracts\\LogProfile interface. Logging request.", [
                    'request_uri' => $request->getRequestUri(),
                ]);

                return true; // Log if contract not implemented
            }

            // Check if the method exists (redundant if using interface, but good practice)
            if (! method_exists($logProfile, 'shouldLog')) {
                Log::warning("RequestLoggerMiddleware: LogProfile class '{$logProfileClass}' does not have a 'shouldLog' method. Logging request.", [
                    'request_uri' => $request->getRequestUri(),
                ]);

                return true; // Log if method is missing
            }

            // Call the profile's method
            return $logProfile->shouldLog($request);

        } catch (Throwable $e) {
            Log::error("RequestLoggerMiddleware: Error while executing LogProfile '{$logProfileClass}'. Logging request.", [
                'exception'   => $e,
                'request_uri' => $request->getRequestUri(),
            ]);

            return true; // Log if the profile throws an exception
        }
    }
}
