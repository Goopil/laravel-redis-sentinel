<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Concerns;

use Goopil\LaravelRedisSentinel\Concerns\Loggable;

class LoggableTestObject
{
    use Loggable;

    public function testLog(string $message, array $context = [], string $level = 'info')
    {
        $this->log($message, $context, $level);
    }
}
