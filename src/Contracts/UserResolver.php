<?php

namespace TheCaretakers\RequestLogger\Contracts;

interface UserResolver
{
    /**
     * Resolve the current user identifier.
     */
    public function resolve(): array;
}
