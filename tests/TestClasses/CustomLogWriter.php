<?php

namespace TheCaretakers\RequestLogger\Tests\TestClasses;

use Illuminate\Support\Facades\Storage;
use TheCaretakers\RequestLogger\Contracts\LogWriter;

class CustomLogWriter implements LogWriter
{
    /**
     * Write the log data to a custom disk and path.
     *
     * @param  array  $logData  The processed and sanitized log data.
     */
    public function write(array $logData): void
    {
        $disk = Storage::disk('custom_disk');
        $path = 'custom-path/custom.log';

        $content = json_encode([
            'message' => 'Logged by CustomLogWriter',
            'data'    => $logData,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put($path, $content);
    }
}
