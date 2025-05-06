<?php

namespace TheCaretakers\RequestLogger\Contracts;

interface LogWriter
{
    /**
     * Write the log data to the desired destination.
     *
     * @param  array  $logData  The processed and sanitized log data.
     */
    public function write(array $logData): void;
}
