<?php

namespace TheCaretakers\RequestLogger;

use TheCaretakers\RequestLogger\Query\RequestLogQueryBuilder;

/**
 * @mixin RequestLogQueryBuilder
 * @method static int count() // Add this line for autocompletion and static analysis
 */
class RequestLog
{
    /**
     * Begin querying the request logs.
     *
     * @return \TheCaretakers\RequestLogger\Query\RequestLogQueryBuilder
     */
    public static function query(): RequestLogQueryBuilder
    {
        return new RequestLogQueryBuilder();
    }
}
