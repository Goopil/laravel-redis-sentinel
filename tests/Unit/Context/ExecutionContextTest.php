<?php

use Goopil\LaravelRedisSentinel\Context\ExecutionContext;
use Goopil\LaravelRedisSentinel\Tests\Support\FakeContext;

test('execution context exposes a stable storage slot', function () {
    $context = new ExecutionContext;

    expect($context->storage())->toBe($context->storage());
});

test('execution context is not in a coroutine without swoole', function () {
    expect(ExecutionContext::inCoroutine())->toBeFalse();
});

test('fake context can switch between slots', function () {
    $context = new FakeContext;
    $a = $context->storage();

    $context->use('b');

    expect($context->storage())->not->toBe($a)
        ->and($context->currentSlot())->toBe('b');
});
