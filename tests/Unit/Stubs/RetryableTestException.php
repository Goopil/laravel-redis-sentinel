<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Stubs;

use RuntimeException;

class RetryableTestException extends RuntimeException
{
    public function __construct(string $message = 'retryable error', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
