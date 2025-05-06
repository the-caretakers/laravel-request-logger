<?php

namespace TheCaretakers\RequestLogger\Tests\TestClasses;

use Illuminate\Http\Request;
use TheCaretakers\RequestLogger\Contracts\LogProfile;

class CustomLogProfile implements LogProfile
{
    /**
     * Determine if the given request should be logged.
     *
     * Only log POST requests for testing purposes.
     */
    public function shouldLog(Request $request): bool
    {
        return $request->isMethod('POST');
    }
}
