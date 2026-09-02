<?php

use Goopil\LaravelRedisSentinel\Concerns\Retryable;

function classifyRetry(Throwable $exception): bool
{
    $stub = new class
    {
        use Retryable;

        public function __construct()
        {
            $this->setRetryMessages(config('phpredis-sentinel.retry.redis.messages'));
            $this->setRetryLimit(0);
            $this->setRetryDelay(1);
        }

        public function attempt(Throwable $toThrow): bool
        {
            $retried = false;

            try {
                $this->retryOnFailure(
                    fn () => throw $toThrow,
                    function () use (&$retried) {
                        $retried = true;
                    }
                );
            } catch (Throwable) {
                // retryLimit 0: onFail already fired if the message matched
            }

            return $retried;
        }
    };

    return $stub->attempt($exception);
}

test('default redis retry messages match only transient failures', function (string $message, bool $expected) {
    expect(classifyRetry(new RedisException($message)))->toBe($expected);
})->with([
    'readonly replica canonical' => ["READONLY You can't write against a read only replica.", true],
    'readonly replica mixed case' => ["READONLY You Can't Write Against A Read Only Replica.", true],
    'loading dataset canonical' => ['Redis is loading the dataset in memory', true],
    'socket error read' => ['socket error on read socket', true],
    'socket error write' => ['socket error on write socket', true],
    'connection reset by peer' => ['Connection reset by peer', true],
    'connection refused' => ['Connection refused', true],
    'went away' => ['Redis server went away', true],
    'read error' => ['read error on connection', true],
    'broken pipe' => ['broken pipe', true],
    'unknown command' => ["ERR unknown command 'FOOBAR'", false],
    'wrongtype' => ['WRONGTYPE Operation against a key holding the wrong kind of value', false],
    'bare socket mention' => ['socket library is disabled in lua scripts', false],
    'noauth' => ['NOAUTH Authentication required.', false],
    'execabort' => ['EXECABORT Transaction discarded because of previous errors.', false],
]);
