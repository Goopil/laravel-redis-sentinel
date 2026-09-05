<?php

namespace Goopil\LaravelRedisSentinel\Horizon;

use ArrayIterator;
use IteratorAggregate;
use Laravel\Horizon\ServiceBindings;

/**
 * @implements IteratorAggregate<string, mixed>
 */
class HorizonServiceBindings implements IteratorAggregate
{
    use ServiceBindings;

    /**
     * @return ArrayIterator<string, mixed>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->serviceBindings);
    }
}
