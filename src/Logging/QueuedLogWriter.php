<?php

namespace TheCaretakers\RequestLogger\Logging;

use TheCaretakers\RequestLogger\Contracts\LogWriter;
use TheCaretakers\RequestLogger\Jobs\ProcessHttpRequestLogJob;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueuedLogWriter implements LogWriter
{
    /**
     * Write the log data by dispatching a job.
     *
     * @param  array  $logData  The processed and sanitized log data.
     */
    public function write(array $logData): void
    {
        try {
            $queueName = config('request-logger.queue_name', 'default');
            $queueConnection = config('request-logger.queue_connection');

            $job = ProcessHttpRequestLogJob::dispatch($logData)->onQueue($queueName);

            if ($queueConnection) {
                $job->onConnection($queueConnection);
            }
        } catch (Throwable $e) {
            // Fallback or error logging if dispatching fails
            Log::error('RequestLogger: Failed to dispatch ProcessHttpRequestLogJob to queue.', [
                'exception' => $e->getMessage(),
                'logData' => $logData,
            ]);
        }
    }
}
