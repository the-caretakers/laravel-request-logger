<?php

namespace TheCaretakers\RequestLogger\Logging;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TheCaretakers\RequestLogger\Contracts\LogWriter;
use Throwable;

class DefaultLogWriter implements LogWriter
{
    public function __construct()
    {
        // Constructor is now empty as UserResolver is handled before this writer is called.
    }

    /**
     * Write the log data using the default channel or filesystem logic.
     *
     * @param  array  $logData  The processed and sanitized log data, now including user_id.
     */
    public function write(array $logData): void
    {
        $logChannel = config('request-logger.log_channel');
        $logFormat = config('request-logger.log_format', 'json');

        if ($logChannel) {
            // Log via Laravel's logging system
            try {
                Log::channel($logChannel)->info('HTTP Request Log', $logData);
            } catch (Throwable $e) {
                Log::error('RequestLoggerMiddleware: Error logging via channel '.$logChannel, [
                    'exception' => $e,
                    'logData'   => $logData,
                ]);
            }
        } else {
            // Log directly to filesystem with proper concurrency handling
            $diskName = config('request-logger.disk');
            if (! $diskName) {
                Log::warning('RequestLoggerMiddleware: Filesystem disk not configured for DefaultLogWriter.');
                return;
            }

            $pathTemplate = config('request-logger.log_path_structure', 'http-logs/{Y}-{m}-{d}.log');
            $filePath = $this->generateFilePath($pathTemplate, $logData['request']['start_time'] ?? null);

            try {
                $disk = Storage::disk($diskName);

                if ($logFormat === 'json') {
                    $logLine = json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
                } else {
                    // Basic line format (can be expanded)
                    $req = $logData['request'];
                    $res = $logData['response'];
                    $logLine = sprintf(
                        "[%s] %s %s - %d %s (%sms)\n",
                        $req['start_time'],
                        $req['method'],
                        $req['uri'],
                        $res['status_code'],
                        $res['status_text'],
                        $res['duration_ms'] ?? 0
                    );
                }

                // Use file locking to prevent race conditions
                if (Str::endsWith($pathTemplate, ['.log', '.jsonl', '.txt'])) {
                    $this->appendWithLock($disk, $filePath, $logLine);
                } else {
                    // Assume individual file per request (e.g., .json)
                    $disk->put($filePath, $logLine);
                }

            } catch (Throwable $e) {
                Log::error('RequestLoggerMiddleware: Failed to write log to disk via DefaultLogWriter.', [
                    'disk'      => $diskName,
                    'path'      => $filePath,
                    'exception' => $e,
                    'logData'   => $logData,
                ]);
            }
        }
    }

    /**
     * Append to file with exclusive locking to prevent race conditions
     */
    protected function appendWithLock($disk, string $filePath, string $content): void
    {
        // Check if this is a local disk by trying to get the path
        try {
            // This method exists for local disks and will throw for others
            $fullPath = $disk->path($filePath);
            $directory = dirname($fullPath);

            // Ensure directory exists
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Use file_put_contents with LOCK_EX and FILE_APPEND flags for atomic writes
            $result = file_put_contents($fullPath, $content, LOCK_EX | FILE_APPEND);

            if ($result === false) {
                throw new \RuntimeException("Failed to write to log file: {$fullPath}");
            }
        } catch (\BadMethodCallException $e) {
            // Not a local disk (e.g., S3), fall back to regular append
            // Note: This still has potential race conditions but cloud storage handles it better
            $disk->append($filePath, $content);
        } catch (Throwable $e) {
            // Any other error, fall back to regular append
            Log::warning('RequestLogger: Could not use file locking, falling back to regular append', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            $disk->append($filePath, $content);
        }
    }

    /**
     * Generates the log file path based on the configured structure.
     * Moved from Middleware to be self-contained.
     */
    protected function generateFilePath(string $pathTemplate, ?string $startTimeString): string
    {
        try {
            // Attempt to create DateTimeImmutable from the start time string
            $now = $startTimeString ? new DateTimeImmutable($startTimeString) : new DateTimeImmutable;
        } catch (Throwable $e) {
            // Fallback if the start time string is invalid
            $now = new DateTimeImmutable;
            Log::warning('RequestLoggerMiddleware: Could not parse start_time for log path generation. Using current time.', [
                'start_time' => $startTimeString,
                'exception'  => $e,
            ]);
        }

        $replacements = [
            '{Y}'    => $now->format('Y'),
            '{m}'    => $now->format('m'),
            '{d}'    => $now->format('d'),
            '{H}'    => $now->format('H'),
            '{uuid}' => Str::uuid()->toString(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pathTemplate);
    }
}
