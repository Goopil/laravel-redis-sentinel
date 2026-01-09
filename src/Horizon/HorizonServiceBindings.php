<?php

namespace Goopil\LaravelRedisSentinel\Horizon;

use ArrayIterator;
use IteratorAggregate;
use Laravel\Horizon\ServiceBindings;

class HorizonServiceBindings implements IteratorAggregate
{
    use ServiceBindings;

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->serviceBindings);
    }
}
