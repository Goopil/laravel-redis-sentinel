<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;

test('need params as array logic', function () {
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $reflection = new ReflectionClass($connector);
    $property = $reflection->getProperty('phpredisVersion');
    $property->setAccessible(true);

    $method = $reflection->getMethod('needParamsAsArray');
    $method->setAccessible(true);

    // Test >= 6.0
    $property->setValue($connector, '6.0.0');
    expect($method->invoke($connector))->toBeTrue();

    $property->setValue($connector, '6.1.0');
    expect($method->invoke($connector))->toBeTrue();

    // Test < 6.0
    $property->setValue($connector, '5.3.7');
    expect($method->invoke($connector))->toBeFalse();

    $property->setValue($connector, '4.0.0');
    expect($method->invoke($connector))->toBeFalse();
});

test('create sentinel instance uses correct path based on version', function () {
    $options = ['host' => '127.0.0.1', 'port' => 26379];

    $connector = new class(app(NodeAddressCache::class)) extends RedisSentinelConnector
    {
        public function setVersion($v)
        {
            $this->phpredisVersion = $v;
        }

        public function callCreateInstance($opts)
        {
            return $this->createSentinelInstance($opts);
        }
    };

    if (class_exists('RedisSentinel')) {
        $connector->setVersion(phpversion('redis'));
        $instance = $connector->callCreateInstance($options);
        expect($instance)->toBeInstanceOf(RedisSentinel::class);
    } else {
        $this->markTestSkipped('RedisSentinel class not found');
    }
});

test('it caches phpredis version', function () {
    $connector = new RedisSentinelConnector(app(NodeAddressCache::class));
    $reflection = new ReflectionClass($connector);
    $property = $reflection->getProperty('phpredisVersion');
    $property->setAccessible(true);

    $method = $reflection->getMethod('needParamsAsArray');
    $method->setAccessible(true);

    expect($property->getValue($connector))->toBeNull();

    $method->invoke($connector);

    expect($property->getValue($connector))->not->toBeNull()
        ->toBe(phpversion('redis'));
});
