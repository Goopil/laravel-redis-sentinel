<?php

namespace Goopil\LaravelRedisSentinel\Context;

interface ConnectionContext
{
    /**
     * Storage slot bound to the current execution unit: the process for
     * sequential runtimes, the coroutine for Swoole/OpenSwoole.
     */
    public function storage(): \ArrayObject;
}
