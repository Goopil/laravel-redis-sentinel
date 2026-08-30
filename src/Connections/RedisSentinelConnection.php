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
 * The single retry layer lives in command() (explicit framework methods route through it
 * via $this->command()) and in the wrappers below for methods that bypass command()
 * (scan-family, pipeline, transaction, subscribe-family). __call is deliberately NOT
 * overridden: the framework's __call routes dynamic commands through $this->command(),
 * so overriding it here would create nested retries (up to (limit+1)^2 attempts).
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
        'exists', 'scan', 'type', 'pttl', 'ttl',
    ];

    /**
     * The master client instance (always writes to master).
     * This reference is kept separate to guarantee writes always go to master.
     */
    protected \Redis $masterClient;

    /**
     * The read-only replica client instance.
     */
    protected ?\Redis $readClient = null;

    /**
     * The master connection creation callback.
     *
     * @var callable|null
     */
    protected $masterConnector;

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
     * @param  \Redis  $client  The master client instance
     * @param  callable|null  $connector  Callback to create a new master connection
     * @param  array  $config  Connection configuration
     * @param  callable|null  $readConnector  Callback to create a new read-only connection
     */
    public function __construct($client, ?callable $connector = null, array $config = [], ?callable $readConnector = null)
    {
        parent::__construct($client, $connector, $config);

        // Store master client separately to guarantee writes always go to master
        $this->masterClient = $client;
        $this->masterConnector = $connector;
        $this->readConnector = $readConnector;
    }

    /**
     * @throws Throwable
     */
    public function scan($cursor, $options = []): mixed
    {
        return $this->retry(
            function () use (&$cursor, $options) {
                return parent::scan($cursor, $options);
            },
            __FUNCTION__,
            function () use (&$cursor) {
                $cursor = null;
            }
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
            function () use ($key, &$cursor, $options) {
                return parent::zscan($key, $cursor, $options);
            },
            __FUNCTION__,
            function () use (&$cursor) {
                $cursor = null;
            }
        );
    }

    /**
     * @throws Throwable
     */
    public function hscan($key, $cursor, $options = []): mixed
    {
        return $this->retry(
            function () use ($key, &$cursor, $options) {
                return parent::hscan($key, $cursor, $options);
            },
            __FUNCTION__,
            function () use (&$cursor) {
                $cursor = null;
            }
        );
    }

    /**
     * @throws Throwable
     */
    public function sscan($key, $cursor, $options = []): mixed
    {
        return $this->retry(
            function () use ($key, &$cursor, $options) {
                return parent::sscan($key, $cursor, $options);
            },
            __FUNCTION__,
            function () use (&$cursor) {
                $cursor = null;
            }
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
    public function flushall(?bool $sync = null): bool|\Redis
    {
        try {
            return $this->retry(
                fn () => parent::flushall($sync),
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
     * Laravel 13+ retries read-only commands internally in PhpRedisConnection::command().
     * Its connector is not Sentinel-aware and it dispatches no events, which would mask
     * failovers and bypass this connection's retry logic. Disable it entirely so this
     * connection's retry() wrapper stays the single retry path.
     *
     * No-op on Laravel 10-12 where the method does not exist on the parent.
     */
    protected function isRetryable($method, array $parameters): bool
    {
        return false;
    }

    /**
     * Execute the given callback with retry logic.
     *
     * This method ensures that:
     * - Write commands ALWAYS use the master client
     * - Read commands use replica (if available) unless wroteToMaster is true
     * - Client references are never mixed or corrupted
     *
     * @throws Throwable
     */
    private function retry(callable $callback, string $name, ?Closure $onFailExtra = null): mixed
    {
        $isReadOnly = $this->isReadOnlyCommand($name);

        $usedClient = null;

        $result = $this->retryOnFailure(
            function () use ($callback, $name, &$usedClient) {
                // Determine which client to use for this command
                $targetClient = $this->resolveClientForCommand($name);
                $usedClient = $targetClient;

                // CRITICAL: Temporarily swap $this->client to the target client
                // This is necessary because parent class methods use $this->client
                // We always restore to masterClient to ensure consistency
                $this->client = $targetClient;

                try {
                    return $callback();
                } finally {
                    // Always restore to master client to ensure $this->client is never corrupted
                    $this->client = $this->masterClient;
                }
            },
            onFail: function ($exception, $attempts) use ($name, $isReadOnly, &$usedClient, $onFailExtra) {
                RedisSentinelConnectionFailed::dispatch($this, $exception, $name, $attempts);

                $onFailExtra?->__invoke();

                if ($usedClient !== $this->masterClient && $this->readConnector) {
                    // The failing attempt ran on the read replica - refresh it
                    $this->readClient = call_user_func($this->readConnector, true);
                } else {
                    // The failing attempt ran on the master (write or sticky/fallback read)
                    // - refresh it
                    $newMasterClient = $this->masterConnector
                        ? call_user_func($this->masterConnector, true)
                        : $this->masterClient;

                    $this->masterClient = $newMasterClient;
                    $this->client = $newMasterClient;
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

        // Mark stickiness for write operations
        if (! $isReadOnly) {
            $this->wroteToMaster = true;
        }

        return $result;
    }

    /**
     * Resolve the client instance for the given command.
     *
     * This method implements the read/write splitting logic:
     * - Write commands ALWAYS return master client (guaranteed)
     * - Read commands return replica IF:
     *   - Read connector is configured
     *   - Not in a transaction/pipeline
     *   - No write has been performed (sticky session)
     *   - Command is actually read-only
     * - Otherwise, return master client
     *
     * @return \Redis The Redis client instance to use for this command
     */
    protected function resolveClientForCommand(string $method): \Redis
    {
        // CRITICAL: Write commands ALWAYS use master
        if (! $this->isReadOnlyCommand($method)) {
            return $this->masterClient;
        }

        // Read command: check if we can use replica
        $canUseReplica = $this->readConnector !== null  // Replica configured
            && $this->transactionLevel === 0            // Not in transaction
            && ! $this->wroteToMaster;                  // No write performed (sticky session)

        if ($canUseReplica) {
            return $this->getReadClient();
        }

        // Fallback to master for read commands if replica not available or sticky
        return $this->masterClient;
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
}
