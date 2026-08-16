<?php

use Goopil\LaravelRedisSentinel\Horizon\HorizonServiceBindings;

test('HorizonServiceBindings getIterator returns ArrayIterator', function () {
    $bindings = new HorizonServiceBindings;

    expect($bindings->getIterator())->toBeInstanceOf(ArrayIterator::class);
});

test('HorizonServiceBindings iterator contains service bindings', function () {
    $bindings = new HorizonServiceBindings;

    $iterator = $bindings->getIterator();

    expect($iterator->count())->toBeGreaterThan(0);
});

test('HorizonServiceBindings can be used in foreach', function () {
    $bindings = new HorizonServiceBindings;

    $count = 0;
    foreach ($bindings as $service) {
        $count++;
    }

    expect($count)->toBeGreaterThan(0);
});

test('HorizonServiceBindings iterator is fresh each call', function () {
    $bindings = new HorizonServiceBindings;

    $first = $bindings->getIterator();
    $second = $bindings->getIterator();

    expect(spl_object_id($first))->not->toBe(spl_object_id($second));
});
