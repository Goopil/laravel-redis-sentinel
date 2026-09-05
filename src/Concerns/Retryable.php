<?php

namespace Goopil\LaravelRedisSentinel\Concerns;

use Illuminate\Support\Str;
use Random\RandomException;
use RedisException;
use Throwable;

trait Retryable
{
    /**
     * The number of times the client attempts to retry a command when it fails
     * to connect to a Redis instance behind Sentinel.
     */
    protected int $retryLimit = 5;

    /**
     * The time in milliseconds to wait before the client retries a failed
     * command.
     */
    protected int $retryDelay = 1000;

    /**
     * The exception message triggering a retry.
     *
     * @var array<int, string>
     */
    protected array $retryMessages = [];

    public function setRetryLimit(int $retryLimit): static
    {
        $this->retryLimit = $retryLimit;

        return $this;
    }

    public function setRetryDelay(int $retryDelay): static
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  array<int, string>  $retryMessages
     */
    public function setRetryMessages(array $retryMessages): static
    {
        $this->retryMessages = array_values(array_filter($retryMessages, 'is_string'));

        return $this;
    }

    /**
     * Only phpredis transport failures are retryable by default: any other
     * Throwable — e.g. an exception raised inside a user callback — must
     * propagate immediately instead of being replayed (#91).
     */
    protected function isRetryableException(Throwable $exception): bool
    {
        return $exception instanceof RedisException;
    }

    /**
     * @return mixed the callback's result
     *
     * @throws Throwable
     */
    protected function retryOnFailure(
        callable $callback,
        ?callable $onFail = null,
        ?callable $onReconnect = null,
        ?callable $onMaxFail = null
    ) {
        $attempts = 0;

        while (true) {
            try {
                $result = $callback();

                if ($attempts > 0 && is_callable($onReconnect)) {
                    $onReconnect($attempts);
                }

                return $result;
            } catch (Throwable $exception) {
                if (! $this->isRetryableException($exception) || empty($this->retryMessages) || ! Str::contains($exception->getMessage(), $this->retryMessages, ignoreCase: true)) {
                    throw $exception;
                }

                $attempts++;

                if (is_callable($onFail)) {
                    $onFail($exception, $attempts);
                }

                if ($attempts > $this->retryLimit) {
                    if (is_callable($onMaxFail)) {
                        $onMaxFail($exception, $attempts);
                    }

                    throw $exception;
                }

                $this->sleepWithBackoff($attempts);
            }
        }
    }

    /**
     * Sleep with exponential backoff and jitter.
     *
     * This method implements an exponential backoff strategy with jitter to avoid
     * the "thundering herd" problem where multiple clients retry at the same time.
     *
     * Formula: min(baseDelay * (2^attempt) + jitter, maxDelay)
     *
     * @param  int  $attempt  The current attempt number (1-based)
     *
     * @throws RandomException
     */
    protected function sleepWithBackoff(int $attempt): void
    {
        // Exponential backoff: base * (2^(attempt-1))
        $exponentialDelay = $this->retryDelay * (2 ** ($attempt - 1));

        // Add random jitter (0 to 50% of base delay)
        $jitter = random_int(0, (int) ($this->retryDelay / 2));

        // Cap at 10 seconds to avoid excessive waits
        $totalDelay = min($exponentialDelay + $jitter, 10000);

        usleep($totalDelay * 1000);
    }
}
