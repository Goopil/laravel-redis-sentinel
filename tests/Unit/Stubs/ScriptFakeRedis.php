<?php

namespace Goopil\LaravelRedisSentinel\Tests\Unit\Stubs;

/**
 * Simulates phpredis >= 6 EVAL semantics without a live server: server errors
 * are NOT thrown, they are stored via getLastError() while eval() returns false
 * (the same reply a successful script returning nil produces).
 */
class ScriptFakeRedis extends \Redis
{
    public ?string $nextError = null;

    private ?string $lastError = null;

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        if ($this->nextError !== null) {
            $this->lastError = $this->nextError;
        }

        return false;
    }

    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
