<?php

namespace TheCaretakers\RequestLogger\Contracts;

use Illuminate\Http\Request;

interface LogProfile
{
    /**
     * Determine if the given request should be logged.
     */
    public function shouldLog(Request $request): bool;
}
