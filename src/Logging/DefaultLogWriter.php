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
    /**
     * Write the log data using the default channel or filesystem logic.
     *
     * @param  array  $logData  The processed and sanitized log data.
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
            // Log directly to filesystem
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
                        "[%s] %s %s - %d %s (%sms)\n", // Changed %dms to %sms for float
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
                Log::error('RequestLoggerMiddleware: Failed to write log to disk via DefaultLogWriter.', [
                    'disk'      => $diskName,
                    'path'      => $filePath,
                    'exception' => $e,
                    'logData'   => $logData, // Include log data for context
                ]);
            }
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
            '{uuid}' => Str::uuid()->toString(), // For unique file names if needed
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pathTemplate);
    }
}
