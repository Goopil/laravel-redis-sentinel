<?php

namespace Goopil\LaravelRedisSentinel\Context;

interface ConnectionContext
{
    /**
     * Storage slot bound to the current execution unit: the process for
     * sequential runtimes, the coroutine for Swoole/OpenSwoole.
     *
     * @return \ArrayObject<string, mixed>
     */
    public function storage(): \ArrayObject;
}
