<?php

namespace TheCaretakers\RequestLogger\Logging;

use Illuminate\Http\Request;
use TheCaretakers\RequestLogger\Contracts\LogProfile;

class DefaultLogProfile implements LogProfile
{
    /**
     * Determine if the given request should be logged.
     * By default, all requests are logged.
     */
    public function shouldLog(Request $request): bool
    {
        return true;
    }
}
