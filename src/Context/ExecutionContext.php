<?php

namespace Goopil\LaravelRedisSentinel\Context;

use Goopil\LaravelRedisSentinel\Exceptions\CoroutineContextException;
use Swoole\Coroutine;

final class ExecutionContext implements ConnectionContext
{
    private static ?bool $coroutineRuntime = null;

    /** @var \ArrayObject<string, mixed> */
    private \ArrayObject $fallback;

    public function __construct()
    {
        $this->fallback = new \ArrayObject;
    }

    public static function inCoroutine(): bool
    {
        if (self::$coroutineRuntime === null) {
            self::$coroutineRuntime = class_exists(Coroutine::class)
                || class_exists(\OpenSwoole\Coroutine::class);
        }

        if (! self::$coroutineRuntime) {
            return false;
        }

        // Runtime detection requires a Swoole/OpenSwoole extension; both branches
        // are unreachable in a plain-PHP test environment.
        // @codeCoverageIgnoreStart
        return class_exists(Coroutine::class)
            ? Coroutine::getCid() > 0
            : \OpenSwoole\Coroutine::getCid() > 0;
        // @codeCoverageIgnoreEnd
    }

    /**
     * @return \ArrayObject<string, mixed>
     */
    public function storage(): \ArrayObject
    {
        if (! self::inCoroutine()) {
            return $this->fallback;
        }

        // @codeCoverageIgnoreStart
        $context = class_exists(Coroutine::class)
            ? Coroutine::getContext()
            : \OpenSwoole\Coroutine::getContext();

        if ($context instanceof \ArrayObject) {
            // Keyed per connection: two connections may share one coroutine.
            return $context['lrs-'.spl_object_id($this)] ??= new \ArrayObject;
        }

        // Silent fallback here would make every coroutine share the worker's
        // state and reintroduce the cross-coroutine bug fixed in #66.
        throw new CoroutineContextException(sprintf(
            '%s returned an unexpected coroutine context (expected ArrayObject), refusing to fall back to shared worker state.',
            class_exists(Coroutine::class) ? 'Swoole' : 'OpenSwoole'
        ));
        // @codeCoverageIgnoreEnd
    }
}
