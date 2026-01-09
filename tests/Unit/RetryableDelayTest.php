<?php

use Goopil\LaravelRedisSentinel\Concerns\Retryable;

test('it waits correct amount of time', function () {
    $retryable = new class
    {
        use Retryable;

        public int $callCount = 0;

        public array $sleeps = [];

        public function setMessages(array $messages)
        {
            $this->retryMessages = $messages;
        }

        public function test_retry()
        {
            return $this->retryOnFailure(
                function () {
                    $this->callCount++;
                    if ($this->callCount <= 2) {
                        throw new Exception('retryable error');
                    }

                    return 'success';
                }
            );
        }

        protected function sleepWithBackoff(int $attempt): void
        {
            // Track the actual delay that would be used
            $exponentialDelay = $this->retryDelay * (2 ** ($attempt - 1));
            $jitter = random_int(0, (int) ($this->retryDelay / 2));
            $totalDelay = min($exponentialDelay + $jitter, 10000);

            $this->sleeps[] = $totalDelay;
        }
    };

    $retryable->setMessages(['retryable error']);
    $retryable->setRetryDelay(100);

    $result = $retryable->test_retry();

    expect($result)->toBe('success')
        ->and($retryable->callCount)->toBe(3)
        ->and($retryable->sleeps)->toHaveCount(2)
        ->and($retryable->sleeps[0])->toBeGreaterThanOrEqual(100)
        ->and($retryable->sleeps[0])->toBeLessThanOrEqual(150)
        ->and($retryable->sleeps[1])->toBeGreaterThanOrEqual(200)
        ->and($retryable->sleeps[1])->toBeLessThanOrEqual(250);
});

test('it stops after retry limit', function () {
    $retryable = new class
    {
        use Retryable;

        public int $callCount = 0;

        public array $sleeps = [];

        public int $maxFailCalls = 0;

        public function setMessages(array $messages): void
        {
            $this->retryMessages = $messages;
        }

        public function test_retry()
        {
            return $this->retryOnFailure(
                function () {
                    $this->callCount++;
                    throw new Exception('retryable error');
                },
                onMaxFail: function () {
                    $this->maxFailCalls++;
                }
            );
        }

        protected function sleepWithBackoff(int $attempt): void
        {
            // Track the actual delay that would be used
            $exponentialDelay = $this->retryDelay * (2 ** ($attempt - 1));
            $jitter = random_int(0, (int) ($this->retryDelay / 2));
            $totalDelay = min($exponentialDelay + $jitter, 10000);

            $this->sleeps[] = $totalDelay;
        }
    };

    $retryable->setMessages(['retryable error']);
    $retryable->setRetryDelay(100);
    $retryable->setRetryLimit(1);

    try {
        $retryable->test_retry();
        // Since we're in a Pest test, fail() is available if we use the underlying PHPUnit,
        // but it's better to use expect()->toThrow() or just catch and assert.
        throw new \Exception('Expected exception was not thrown.');
    } catch (Exception $exception) {
        if ($exception->getMessage() === 'Expected exception was not thrown.') {
            throw $exception;
        }
        expect($exception->getMessage())->toBe('retryable error');
    }

    expect($retryable->callCount)->toBe(2)
        ->and($retryable->sleeps)->toHaveCount(1)
        ->and($retryable->sleeps[0])->toBeGreaterThanOrEqual(100)
        ->and($retryable->sleeps[0])->toBeLessThanOrEqual(150)
        ->and($retryable->maxFailCalls)->toBe(1);
});
