<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Facades\Log;

trait Loggable
{
    protected ?string $logPrefix = null;

    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        Log::channel(config('phpredis-sentinel.log.channel'))
            ->{$level}(sprintf('[%s] %s',
                $this->getLogPrefix(),
                $message
            ), $context);
    }

    protected function getLogPrefix(): string
    {
        if ($this->logPrefix === null) {
            $this->logPrefix = class_basename(static::class);
        }

        return $this->logPrefix;
    }
}
