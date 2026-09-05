<?php

namespace Goopil\LaravelRedisSentinel\Connections;

use Closure;
use Goopil\LaravelRedisSentinel\Concerns\Loggable;
use Goopil\LaravelRedisSentinel\Concerns\Retryable;
use Goopil\LaravelRedisSentinel\Context\ConnectionContext;
use Goopil\LaravelRedisSentinel\Context\ConnectionState;
use Goopil\LaravelRedisSentinel\Context\ExecutionContext;
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
 * NOTE: PacksPhpRedisValues::pack() (used by RedisStore/PhpRedisLock) reads $this->client
 * outside the swap — benign because all context clients share the same connection
 * options/serializer.
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
        'object',
    ];

    /** @var array<int, string>|null */
    protected ?array $readOnlyCommands = null;

    /**
     * The master client instance provided at construction time. Non-split
     * connections always use it; split connections use it as the worker/fallback
     * state master so FPM never creates extra connections.
     */
    protected \Redis $initialMaster;

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
     * Per-execution-unit storage (process slot for FPM/CLI, coroutine slot for
     * Swoole/OpenSwoole). Lazily created on first state access. It is only an
     * ArrayObject holder: non-split connections short-circuit per-context
     * client creation elsewhere, so routing never builds clients from it.
     */
    private ?ConnectionContext $context = null;

    /**
     * Create a new Redis Sentinel connection.
     *
     * @param  \Redis  $client  The master client instance
     * @param  callable|null  $connector  Callback to create a new master connection
     * @param  array  $config  Connection configuration
     * @param  callable|null  $readConnector  Callback to create a new read-only connection
     * @param  ConnectionContext|null  $context  Execution context store; defaults to a process/coroutine-aware one
     */
    public function __construct($client, ?callable $connector = null, array $config = [], ?callable $readConnector = null, ?ConnectionContext $context = null)
    {
        parent::__construct($client, $connector, $config);

        $this->initialMaster = $client;
        $this->masterConnector = $connector;
        $this->readConnector = $readConnector;
        $this->context = $context;

        // Seed the current (worker) slot with the constructor client: the FPM/CLI
        // fallback keeps using it and creates no extra connection.
        $this->state()->master = $client;

        $this->readOnlyCommands = array_unique(array_merge(
            self::READ_ONLY_COMMAND,
            array_map('strtolower', $config['read_commands'] ?? []),
        ));
    }

    /**
     * The state of the current execution unit: stickiness, transaction level and
     * its lazily created master/replica clients.
     */
    private function state(): ConnectionState
    {
        // Lazy fallback for partially mocked instances (no constructor ran).
        $context = $this->context ??= new ExecutionContext;

        return $context->storage()['state'] ??= new ConnectionState;
    }

    /**
     * The current execution unit's master client: the constructor client in
     * non-split mode, the context's lazily created one in split mode.
     */
    private function contextMaster(): \Redis
    {
        $state = $this->state();

        return $state->master ??= $this->masterConnector
            ? ($this->masterConnector)(false)
            : $this->initialMaster;
    }

    /**
     * Get the underlying Redis client: the current execution unit's master.
     *
     * Laravel's own pipeline()/transaction() call this method, which routes them
     * to the right per-context master.
     */
    public function client(): \Redis
    {
        return $this->readConnector === null ? $this->initialMaster : $this->contextMaster();
    }

    /**
     * Reconnect a client after a failed attempt. A reconnect that throws must
     * not short-circuit the retry loop: the next attempt rethrows the command
     * failure and the max-retry bookkeeping can still run (#47).
     */
    private function reconnectClient(callable $connector, string $name): ?\Redis
    {
        try {
            return call_user_func($connector, true);
        } catch (Throwable $reconnectException) {
            $this->log($name.' - reconnect failed', [
                'method' => $name,
                'reason' => $reconnectException->getMessage(),
                'connection' => $this->name,
            ], 'error');

            return null;
        }
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
     * @param  bool|null  $async  true requests a non-blocking flush, null/false blocks
     *
     * @throws Throwable
     */
    public function flushdb($async = null): mixed
    {
        try {
            return $this->command('flushdb', $this->asyncFlushArguments((bool) $async));
        } finally {
            // Reset stickiness after flushing since all data is gone
            $this->state()->wroteToMaster = false;
        }
    }

    /**
     * Remove all keys from all databases and reset stickiness.
     *
     * @param  bool|null  $sync  true/null perform a blocking flush, false requests a non-blocking flush
     *
     * @throws Throwable
     */
    public function flushall(?bool $sync = null): bool|\Redis
    {
        try {
            return $this->command('flushall', $sync === false ? $this->asyncFlushArguments(true) : []);
        } finally {
            // Reset stickiness after flushing since all data is gone
            $this->state()->wroteToMaster = false;
        }
    }

    /**
     * phpredis 5.x selects ASYNC for any truthy argument; phpredis 6.x flipped the
     * meaning (true = SYNC, false = ASYNC) — verified on the wire with MONITOR.
     * Return the argument list that selects ASYNC on the installed extension,
     * or an empty list for a blocking flush.
     *
     * @return array<int, bool|string>
     */
    private function asyncFlushArguments(bool $async): array
    {
        if (! $async) {
            return [];
        }

        return version_compare((string) phpversion('redis'), '6.0', '>=')
            ? [false]
            : ['ASYNC'];
    }

    /**
     * @throws Throwable
     */
    public function pipeline(?callable $callback = null): array|\Redis
    {
        $state = $this->state();
        $state->transactionLevel++;

        try {
            return $this->retry(
                fn () => parent::pipeline($callback),
                __FUNCTION__
            );
        } finally {
            $state->transactionLevel--;
        }
    }

    /**
     * @throws Throwable
     */
    public function transaction(?callable $callback = null): array|\Redis
    {
        $state = $this->state();
        $state->transactionLevel++;

        try {
            return $this->retry(
                fn () => parent::transaction($callback),
                __FUNCTION__
            );
        } finally {
            $state->transactionLevel--;
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
            function () use ($method, $parameters) {
                $connector = $this->connector;
                $this->connector = null;

                try {
                    return parent::command($method, $parameters);
                } finally {
                    $this->connector = $connector;
                }
            },
            $method
        );
    }

    /**
     * Laravel retries read-only commands internally in PhpRedisConnection::command()
     * by calling the connector closure with $refresh = false, reconnecting to the
     * cached (possibly demoted) node without dispatching events; on Laravel 13+ it
     * also nests retries. Neutralize it entirely so this connection's retry() wrapper
     * stays the single retry path.
     *
     * No-op on Laravel 10-12, where the parent has no isRetryable() method.
     *
     * The connector nulling above also neutralizes the parent's catch-block
     * reconnect (which passes $refresh = false) on all supported versions.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $parameters
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
                // This is necessary because parent class methods use $this->client.
                // Routing never reads $this->client outside this swap window, which
                // is what makes the swap safe under cooperative scheduling: with 3+
                // interleaved coroutines the restores cascade and can leave $this->client
                // pointing at a foreign context's master — harmless, since no command is
                // ever dispatched through the stale value.
                $previous = $this->client;
                $this->client = $targetClient;

                try {
                    return $callback();
                } finally {
                    $this->client = $previous;
                }
            },
            onFail: function ($exception, $attempts) use ($name, $isReadOnly, &$usedClient, $onFailExtra) {
                RedisSentinelConnectionFailed::dispatch($this, $exception, $name, $attempts);

                $onFailExtra?->__invoke();

                $state = $this->state();

                if ($usedClient !== $state->master && $this->readConnector) {
                    // The failing attempt ran on the read replica - refresh it
                    $refreshed = $this->reconnectClient($this->readConnector, $name);

                    if ($refreshed !== null) {
                        $state->read = $refreshed;
                    }
                } elseif ($this->masterConnector) {
                    // The failing attempt ran on the master (write or sticky/fallback read)
                    // - refresh it
                    $refreshed = $this->reconnectClient($this->masterConnector, $name);

                    if ($refreshed !== null) {
                        $state->master = $refreshed;
                        $this->initialMaster = $refreshed;
                        $this->client = $refreshed;
                    }
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
            $this->state()->wroteToMaster = true;
        }

        return $result;
    }

    /**
     * Resolve the client instance for the given command.
     *
     * This method implements the read/write splitting logic:
     * - Non-split connections always use the constructor client
     * - Write commands ALWAYS return the context's master client (guaranteed)
     * - Read commands return the context's replica IF:
     *   - Not in a transaction/pipeline
     *   - No write has been performed (sticky session)
     *   - Command is actually read-only
     * - Otherwise, return the context's master client
     *
     * @return \Redis The Redis client instance to use for this command
     */
    protected function resolveClientForCommand(string $method): \Redis
    {
        // Non-split mode: everything keeps using the constructor-provided client
        if ($this->readConnector === null) {
            return $this->initialMaster;
        }

        // CRITICAL: Write commands ALWAYS use master
        if (! $this->isReadOnlyCommand($method)) {
            return $this->contextMaster();
        }

        $state = $this->state();

        // Read command: check if we can use the context's replica
        if ($state->transactionLevel === 0 && ! $state->wroteToMaster) {
            return $this->getReadClient();
        }

        // Fallback to the context's master for read commands if sticky
        return $this->contextMaster();
    }

    /**
     * Reset the sticky master flag.
     */
    public function resetStickiness(): void
    {
        $state = $this->state();
        $state->wroteToMaster = false;
        $state->transactionLevel = 0;
    }

    /**
     * Get the read-only client instance of the current execution unit.
     */
    public function getReadClient(): \Redis
    {
        $state = $this->state();

        if ($state->read) {
            return $state->read;
        }

        if ($this->readConnector) {
            return $state->read = call_user_func($this->readConnector);
        }

        return $this->initialMaster;
    }

    /**
     * Disconnect the current execution unit's clients. In non-split mode this
     * closes the constructor client, exactly like the parent implementation.
     */
    public function disconnect(): void
    {
        if ($this->readConnector === null) {
            parent::disconnect();

            return;
        }

        $state = $this->state();

        try {
            $state->master?->close();
        } finally {
            $state->read?->close();
            $state->master = null;
            $state->read = null;
        }
    }

    /**
     * Determine if the given command is a read-only command.
     */
    protected function isReadOnlyCommand(string $method): bool
    {
        return in_array(strtolower($method), $this->readOnlyCommands ?? self::READ_ONLY_COMMAND);
    }
}
