<?php

namespace TheCaretakers\RequestLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TheCaretakers\RequestLogger\Logging\DefaultLogWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessHttpRequestLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $logData;

    /**
     * Create a new job instance.
     *
     * @param  array  $logData
     * @return void
     */
    public function __construct(array $logData)
    {
        $this->logData = $logData;
    }

    /**
     * Execute the job.
     *
     * @param  DefaultLogWriter  $writer
     * @return void
     */
    public function handle(DefaultLogWriter $writer): void
    {
        try {
            $writer->write($this->logData);
        } catch (Throwable $e) {
            Log::error('ProcessHttpRequestLogJob failed', [
                'exception' => $e->getMessage(),
                'logData' => $this->logData, // Be cautious about logging sensitive data here
            ]);
        }
    }
}
