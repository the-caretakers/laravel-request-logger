<?php

namespace TheCaretakers\RequestLogger\Resolvers;

use Illuminate\Support\Facades\Auth;
use TheCaretakers\RequestLogger\Contracts\UserResolver;

class DefaultUserResolver implements UserResolver
{
    /**
     * Resolve the current user identifier.
     */
    public function resolve(): array
    {
        return [
            'id' => Auth::id(),
        ];
    }
}
