<?php

namespace Goopil\LaravelRedisSentinel\Connections;

use Closure;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Concerns\Retryable;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionMaxRetryFailed;
use Goopil\LaravelRedisSentinel\Events\RedisSentinelConnectionReconnected;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use RedisException;
use Throwable;

/**
 * The connection to Redis after connecting through a Sentinel using the PhpRedis extension.
 *
 * NOTE: Most Redis commands (get, set, mget, etc.) are NOT explicitly overridden here.
 * They are handled by the parent class calling command() or via __call(), both of which
 * are wrapped by our retry() method. This avoids "Nested Retries" where a method-level
 * retry would wrap a command-level retry, leading to an exponential number of attempts.
 *
 * @method mixed get(string $key) Get the value of a key
 * @method bool set(string $key, mixed $value, mixed $expireResolution = null, mixed $expireTTL = null, mixed $flag = null) Set the string value of a key
 * @method int|false del(string|array $key, ...$other_keys) Delete one or more keys
 * @method bool exists(string|array $key) Determine if a key exists
 * @method int expire(string $key, int $seconds) Set a key's time to live in seconds
 * @method int ttl(string $key) Get the time to live for a key in seconds
 * @method array mget(array $keys) Get the values of all the given keys
 * @method bool mset(array $array) Set multiple keys to multiple values
 * @method int|false incr(string $key) Increment the integer value of a key by one
 * @method int|false decr(string $key) Decrement the integer value of a key by one
 * @method int|false incrBy(string $key, int $value) Increment the integer value of a key by the given amount
 * @method float|false incrByFloat(string $key, float $value) Increment the float value of a key by the given amount
 * @method mixed hGet(string $key, string $field) Get the value of a hash field
 * @method bool hSet(string $key, string $field, mixed $value) Set the string value of a hash field
 * @method int|false hDel(string $key, string $field, ...$other_fields) Delete one or more hash fields
 * @method bool hExists(string $key, string $field) Determine if a hash field exists
 * @method array hGetAll(string $key) Get all the fields and values in a hash
 * @method array hKeys(string $key) Get all the fields in a hash
 * @method array hVals(string $key) Get all the values in a hash
 * @method int hLen(string $key) Get the number of fields in a hash
 * @method int|false lPush(string $key, ...$values) Prepend one or multiple values to a list
 * @method int|false rPush(string $key, ...$values) Append one or multiple values to a list
 * @method mixed lPop(string $key) Remove and get the first element in a list
 * @method mixed rPop(string $key) Remove and get the last element in a list
 * @method int lLen(string $key) Get the length of a list
 * @method array lRange(string $key, int $start, int $stop) Get a range of elements from a list
 * @method int|false sAdd(string $key, ...$values) Add one or more members to a set
 * @method int|false sRem(string $key, ...$values) Remove one or more members from a set
 * @method array sMembers(string $key) Get all the members in a set
 * @method bool sIsMember(string $key, mixed $value) Determine if a given value is a member of a set
 * @method int sCard(string $key) Get the number of members in a set
 * @method int|false zAdd(string $key, mixed $options, mixed $score1, mixed $value1 = null, mixed $score2 = null, mixed $value2 = null) Add one or more members to a sorted set, or update its score if it already exists
 * @method int|false zRem(string $key, ...$values) Remove one or more members from a sorted set
 * @method array zRange(string $key, int $start, int $stop, bool $withScores = false) Return a range of members in a sorted set, by index
 * @method array zRevRange(string $key, int $start, int $stop, bool $withScores = false) Return a range of members in a sorted set, by index, with scores ordered from high to low
 * @method int zCard(string $key) Get the number of members in a sorted set
 * @method float|false zScore(string $key, mixed $member) Get the score associated with the given member in a sorted set
 */
class RedisSentinelConnection extends PhpRedisConnection
{
    use Loggable;
    use Retryable;

    /**
     * Allowed read-only commands.
     */
    protected const READ_ONLY_COMMAND = [
        'get', 'bitcount', 'bitpos', 'getbit', 'getrange', 'strlen', 'mget',
        'hget', 'hgetall', 'hkeys', 'hlen', 'hmget', 'hexists', 'hvals', 'hstrlen', 'hscan',
        'lindex', 'llen', 'lrange',
        'scard', 'sismember', 'smismember', 'smembers', 'srandmember', 'sscan',
        'zcard', 'zcount', 'zlexcount', 'zrange', 'zrank', 'zrevrange', 'zrevrank', 'zscore', 'zscan',
        'zrangebyscore', 'zrevrangebyscore', 'zrangebylex', 'zrevrangebylex',
        'exists', 'keys', 'scan', 'type', 'pttl', 'ttl', 'info', 'memory',
    ];

    /**
     * The read-only client instance.
     */
    protected ?\Redis $readClient = null;

    /**
     * The read-only connection creation callback.
     *
     * @var callable|null
     */
    protected $readConnector;

    /**
     * Indicates if a write operation has been performed.
     */
    protected bool $wroteToMaster = false;

    /**
     * The number of active transactions/pipelines.
     */
    protected int $transactionLevel = 0;

    /**
     * Create a new Redis Sentinel connection.
     *
     * @param  \Redis  $client
     */
    public function __construct($client, ?callable $connector = null, array $config = [], ?callable $readConnector = null)
    {
        parent::__construct($client, $connector, $config);
        $this->readConnector = $readConnector;
    }

    /**
     * @throws Throwable
     */
    public function scan($cursor, $options = []): mixed
    {
        return $this->retry(
            fn () => parent::scan($cursor, $options),
            __FUNCTION__
        );
    }

    /**
     * Scans the given set for all values based on options.
     *
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array  $options
     */
    /**
     * @throws Throwable
     */
    public function zscan($key, $cursor, $options = []): mixed
    {
        return $this->retry(
            fn () => parent::zscan($key, $cursor, $options),
            __FUNCTION__
        );
    }

    /**
     * @throws Throwable
     */
    public function hscan($key, $cursor, $options = []): mixed
    {
        return $this->retry(
            fn () => parent::hscan($key, $cursor, $options),
            __FUNCTION__
        );
    }

    /**
     * @throws Throwable
     */
    public function sscan($key, $cursor, $options = []): mixed
    {
        return $this->retry(
            fn () => parent::sscan($key, $cursor, $options),
            __FUNCTION__
        );
    }

    /**
     * Remove all keys from the current database and reset stickiness.
     *
     * @throws Throwable
     */
    public function flushdb($async = null): mixed
    {
        try {
            return $this->retry(
                fn () => parent::flushdb($async),
                __FUNCTION__
            );
        } finally {
            // Reset stickiness after flushing since all data is gone
            $this->wroteToMaster = false;
        }
    }

    /**
     * Remove all keys from all databases and reset stickiness.
     *
     * @throws Throwable
     */
    public function flushall($async = null): mixed
    {
        try {
            return $this->retry(
                fn () => parent::flushall($async),
                __FUNCTION__
            );
        } finally {
            // Reset stickiness after flushing since all data is gone
            $this->wroteToMaster = false;
        }
    }

    /**
     * @throws Throwable
     */
    public function pipeline(?callable $callback = null): array|\Redis
    {
        $this->transactionLevel++;

        try {
            return $this->retry(
                fn () => parent::pipeline($callback),
                __FUNCTION__
            );
        } finally {
            $this->transactionLevel--;
        }
    }

    /**
     * @throws Throwable
     */
    public function transaction(?callable $callback = null): array|\Redis
    {
        $this->transactionLevel++;

        try {
            return $this->retry(
                fn () => parent::transaction($callback),
                __FUNCTION__
            );
        } finally {
            $this->transactionLevel--;
        }
    }

    /**
     * @throws Throwable
     */
    public function subscribe($channels, Closure $callback): void
    {
        $this->retry(
            fn () => parent::subscribe($channels, $callback),
            __FUNCTION__
        );
    }

    /**
     * @throws Throwable
     */
    public function psubscribe($channels, Closure $callback): void
    {
        $this->retry(
            fn () => parent::psubscribe($channels, $callback),
            __FUNCTION__
        );
    }

    /**
     * @throws Throwable
     * @throws RedisException
     */
    public function command($method, array $parameters = []): mixed
    {
        return $this->retry(
            fn () => parent::command($method, $parameters),
            $method
        );
    }

    /**
     * Execute the given callback with retry logic.
     *
     * @throws Throwable
     */
    private function retry(callable $callback, string $name): mixed
    {
        $isReadOnly = $this->isReadOnlyCommand($name);

        $result = $this->retryOnFailure(
            function () use ($callback, $name) {
                $clientBefore = $this->client;
                $this->client = $this->resolveClientForCommand($name);

                try {
                    return $callback();
                } finally {
                    $this->client = $clientBefore;
                }
            },
            onFail: function ($exception, $attempts) use ($name, $isReadOnly) {
                RedisSentinelConnectionFailed::dispatch($this, $exception, $name, $attempts);

                if ($isReadOnly && $this->readConnector) {
                    $this->readClient = call_user_func($this->readConnector, true);
                } else {
                    $this->client = $this->connector ? call_user_func($this->connector, true) : $this->client;
                }

                $this->log($name.' - retry', [
                    'method' => $name,
                    'reason' => $exception->getMessage(),
                    'attempts' => $attempts,
                    'connection' => $this->name,
                    'read_only' => $isReadOnly,
                ], 'error');
            },
            onReconnect: function ($attempts) use ($name) {
                RedisSentinelConnectionReconnected::dispatch($this, $name, $attempts);

                $this->log($name.' - reconnected', [
                    'method' => $name,
                    'connection' => $this->name,
                    'attempts' => $attempts,
                ]);
            },
            onMaxFail: function ($exception, $attempts) use ($name) {
                RedisSentinelConnectionMaxRetryFailed::dispatch($this, $exception, $name, $attempts);

                $this->log($name.' - max fail', [
                    'method' => $name,
                    'reason' => $exception->getMessage(),
                    'attempts' => $attempts,
                    'connection' => $this->name,
                ], 'error');
            }
        );

        if (! $isReadOnly) {
            $this->wroteToMaster = true;
        }

        return $result;
    }

    /**
     * Resolve the client instance for the given command.
     */
    protected function resolveClientForCommand(string $method): \Redis
    {
        if ($this->readConnector !== null &&
            $this->transactionLevel === 0 &&
            ! $this->wroteToMaster &&
            $this->isReadOnlyCommand($method)) {
            return $this->getReadClient();
        }

        return $this->client;
    }

    /**
     * Reset the sticky master flag.
     */
    public function resetStickiness(): void
    {
        $this->wroteToMaster = false;
    }

    /**
     * Get the read-only client instance.
     */
    public function getReadClient(): \Redis
    {
        if ($this->readClient) {
            return $this->readClient;
        }

        if ($this->readConnector) {
            return $this->readClient = call_user_func($this->readConnector);
        }

        return $this->client;
    }

    /**
     * Determine if the given command is a read-only command.
     */
    protected function isReadOnlyCommand(string $method): bool
    {
        return in_array(strtolower($method), static::READ_ONLY_COMMAND);
    }

    /**
     * Dynamically pass method calls to the Redis client.
     *
     * This magic method handles all Redis commands that are not explicitly defined in this class.
     * It provides automatic retry logic with exponential backoff and intelligent read/write splitting.
     *
     * Read/Write Splitting Behavior:
     * - Read-only commands (get, hget, lrange, etc.) are routed to replica nodes when available
     * - Write commands (set, hset, lpush, etc.) are always routed to the master node
     * - After a write operation, subsequent reads use the master (sticky sessions) to avoid replication lag
     * - During transactions/pipelines, all commands are routed to the master
     *
     * Retry Logic:
     * - Automatically retries on connection failures (broken pipe, connection lost, etc.)
     * - Uses exponential backoff with jitter to avoid thundering herd
     * - Respects configured retry limits (default: 5 attempts)
     * - Refreshes connections between retry attempts
     *
     * @param  string  $method  The Redis command name (case-insensitive)
     * @param  array  $parameters  The command parameters
     * @return mixed The result from Redis
     *
     * @throws RedisException If Redis operation fails after all retry attempts
     * @throws Throwable If a non-retryable error occurs
     *
     * @see retry() For the retry logic implementation
     * @see isReadOnlyCommand() For the list of read-only commands
     * @see getClientForCommand() For read/write routing logic
     */
    public function __call($method, $parameters): mixed
    {
        return $this->retry(
            fn () => parent::__call(strtolower($method), $parameters),
            $method
        );
    }
}
