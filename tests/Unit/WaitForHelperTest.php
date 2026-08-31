<?php

test('it returns the first truthy value when the condition is immediately met', function () {
    $start = microtime(true);

    $result = waitFor(fn (): string => 'master');

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($result)->toBe('master')
        ->and($elapsedMs)->toBeLessThan(100);
});

test('it polls until the condition becomes truthy', function () {
    $attempts = 0;

    $start = microtime(true);

    $result = waitFor(function () use (&$attempts): string|bool {
        $attempts++;

        return $attempts >= 4 ? 'promoted' : false;
    }, 5000, 20);

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($result)->toBe('promoted')
        ->and($attempts)->toBe(4)
        ->and($elapsedMs)->toBeGreaterThanOrEqual(40)
        ->and($elapsedMs)->toBeLessThan(300);
});

test('it throws a runtime exception when the deadline is exceeded', function () {
    $start = microtime(true);

    expect(fn () => waitFor(fn (): bool => false, 100, 20))
        ->toThrow(RuntimeException::class, 'Condition not met within 100ms');

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($elapsedMs)->toBeGreaterThanOrEqual(100);
});
