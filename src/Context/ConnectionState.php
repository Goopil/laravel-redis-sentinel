<?php

namespace Goopil\LaravelRedisSentinel\Context;

final class ConnectionState
{
    public ?\Redis $master = null;

    public ?\Redis $read = null;

    public bool $wroteToMaster = false;

    public int $transactionLevel = 0;
}
