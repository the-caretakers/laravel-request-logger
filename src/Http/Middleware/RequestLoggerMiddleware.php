<?php

namespace TheCaretakers\RequestLogger\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response; // Corrected namespace for Response
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TheCaretakers\RequestLogger\Contracts\LogProfile;
use TheCaretakers\RequestLogger\Contracts\LogWriter;
use TheCaretakers\RequestLogger\Contracts\UserResolver; // Added UserResolver contract
use TheCaretakers\RequestLogger\Logging\DefaultLogProfile;
use TheCaretakers\RequestLogger\Logging\DefaultLogWriter;
use Throwable;

class RequestLoggerMiddleware
{
    protected array $logData = [];

    protected ?DateTimeImmutable $startTime = null;

    /**
     * Create a new middleware instance.
     *
     * @return void
     */
    public function __construct(protected Application $app)
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

        // Check if logging is enabled globally
        if (! config('request-logger.enabled', true)) {
            return $next($request);
        }

        // Decide whether to log this request before doing anything else
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
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     */
    public function terminate(Request $request, $response): void
    {
        // Check if logging is enabled globally (again, in case config changed during request)
        if (! config('request-logger.enabled', true)) {
            return;
        }

        // If startTime is null, it means shouldLog returned false in handle()
        if ($this->startTime === null) {
            return;
        }

        try {
            $endTime = new DateTimeImmutable;

            // Calculate and round response time to 3 decimal places
            $startTimeFloat = (float) $this->startTime->format('U.u');
            $endTimeFloat = (float) $endTime->format('U.u');
            $durationMs = round(($endTimeFloat - $startTimeFloat) * 1000, 3);

            $this->logData['request']['headers'] = $this->sanitize($request->headers->all(), config('request-logger.sensitive_keywords', []));

            if (config('request-logger.log_request_body', true)) {
                // Handle different content types appropriately
                $contentType = $request->header('Content-Type');
                if (Str::contains($contentType, ['/json', '+json'])) {
                    $body = $request->json()->all(); // Get as array
                } elseif ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
                    $body = $request->input(); // Get form data
                    // Note: File uploads are not directly in input()
                } else {
                    $body = [];
                }
                $this->logData['request']['body'] = $this->sanitize($body, config('request-logger.sensitive_keywords', []));
            } else {
                $this->logData['request']['body'] = '[NOT LOGGED]';
            }

            $this->logData['response'] = [
                'end_time'    => $endTime->format('Y-m-d H:i:s.u P'),
                'duration_ms' => $durationMs,
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

            // Resolve User ID before writing log
            $userResolver = $this->app->make(UserResolver::class);
            if ($userResolver instanceof UserResolver) {
                $this->logData['user'] = $userResolver->resolve();
            } else {
                $this->logData['user'] = null;
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
     * Writes the collected log data using the configured or default LogWriter.
     */
    protected function writeLog(): void
    {
        $logWriterClass = config('request-logger.log_writer') ?: DefaultLogWriter::class;
        $logWriter = null;

        try {
            if (! class_exists($logWriterClass)) {
                Log::warning("RequestLoggerMiddleware: LogWriter class '{$logWriterClass}' not found. Falling back to DefaultLogWriter.");
                $logWriterClass = DefaultLogWriter::class;
            }

            $logWriter = $this->app->make($logWriterClass);

            if (! $logWriter instanceof LogWriter) {
                Log::warning("RequestLoggerMiddleware: Configured LogWriter class '{$logWriterClass}' does not implement LogWriter interface. Falling back to DefaultLogWriter.");
                // Instantiate the default writer if the custom one was invalid
                $logWriter = $this->app->make(DefaultLogWriter::class);
            } elseif (! method_exists($logWriter, 'write')) {
                // This check is technically redundant due to the interface, but kept for safety
                Log::warning("RequestLoggerMiddleware: LogWriter class '{$logWriterClass}' does not have a 'write' method. Falling back to DefaultLogWriter.");
                $logWriter = $this->app->make(DefaultLogWriter::class);
            }

            // Use the resolved writer (either custom or default)
            $logWriter->write($this->logData);

        } catch (Throwable $e) {
            Log::error("RequestLoggerMiddleware: Error executing LogWriter '{$logWriterClass}'.", [
                'exception' => $e,
                'logData'   => $this->logData,
            ]);
            try {
                Log::error('Failed to execute configured LogWriter', ['writer' => $logWriterClass, 'error' => $e->getMessage()]);
            } catch (Throwable $logException) {
                // If even logging the error fails, do nothing further.
            }
        }
    }

    /**
     * Determines if the current request should be logged based on the LogProfile.
     */
    protected function shouldLog(Request $request): bool
    {
        $logProfileClass = config('request-logger.log_profile') ?: DefaultLogProfile::class;
        $logProfile = null;

        try {
            if (! class_exists($logProfileClass)) {
                Log::warning("RequestLoggerMiddleware: LogProfile class '{$logProfileClass}' not found. Falling back to DefaultLogProfile.");
                $logProfileClass = DefaultLogProfile::class;
            }

            $logProfile = $this->app->make($logProfileClass);

            if (! $logProfile instanceof LogProfile) {
                Log::warning("RequestLoggerMiddleware: Configured LogProfile class '{$logProfileClass}' does not implement LogProfile interface. Falling back to DefaultLogProfile.");
                $logProfile = $this->app->make(DefaultLogProfile::class);
            } elseif (! method_exists($logProfile, 'shouldLog')) {
                Log::warning("RequestLoggerMiddleware: LogProfile class '{$logProfileClass}' does not have a 'shouldLog' method. Falling back to DefaultLogProfile.");
                $logProfile = $this->app->make(DefaultLogProfile::class);
            }

            // Call the profile's method
            return $logProfile->shouldLog($request);

        } catch (Throwable $e) {
            Log::error("RequestLoggerMiddleware: Error executing LogProfile '{$logProfileClass}'. Logging request as fallback.", [
                'exception'   => $e,
                'request_uri' => $request->getRequestUri(),
            ]);

            // Log if the profile throws an exception
            return true;
        }
    }
}
