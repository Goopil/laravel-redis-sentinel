<?php

namespace Goopil\LaravelRedisSentinel\Tests\Support;

use Goopil\LaravelRedisSentinel\Context\ConnectionContext;

final class FakeContext implements ConnectionContext
{
    private string $slot = 'default';

    /** @var array<string, \ArrayObject> */
    private array $slots = [];

    public function use(string $slot): void
    {
        $this->slot = $slot;
    }

    public function currentSlot(): string
    {
        return $this->slot;
    }

    public function storage(): \ArrayObject
    {
        return $this->slots[$this->slot] ??= new \ArrayObject;
    }
}
