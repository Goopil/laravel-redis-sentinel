<?php

use Goopil\LaravelRedisSentinel\Connectors\NodeAddressCache;
use Goopil\LaravelRedisSentinel\Connectors\RedisSentinelConnector;
use Goopil\LaravelRedisSentinel\Exceptions\ConfigurationException;

beforeEach(function () {
    $breakers = new ReflectionProperty(RedisSentinelConnector::class, 'resolutionBreakers');
    $breakers->setAccessible(true);
    $breakers->setValue(null, []);
});

function sslConnector(): RedisSentinelConnector
{
    return new class(app(NodeAddressCache::class), app('config')) extends RedisSentinelConnector
    {
        public ?array $capturedOptions = null;

        public function withPhpRedisVersion(string $version): static
        {
            (new ReflectionProperty($this, 'phpredisVersion'))->setValue($this, $version);

            return $this;
        }

        public function testConnectToSentinel(array $config): RedisSentinel
        {
            return $this->connectToSentinel($config);
        }

        protected function createSentinelInstance(array $options): RedisSentinel
        {
            $this->capturedOptions = $options;

            $sentinel = Mockery::mock(RedisSentinel::class);
            $sentinel->shouldReceive('ping')->andReturnTrue();

            return $sentinel;
        }
    };
}

test('sentinel.ssl is passed as a flat ssl option to RedisSentinel', function () {
    $config = ['sentinel' => [
        'host' => '127.0.0.1', 'port' => 26379,
        'ssl' => ['cafile' => '/tmp/ca.crt'],
    ]];

    $connector = sslConnector()->withPhpRedisVersion('6.0.0');
    $connector->testConnectToSentinel($config);

    expect($connector->capturedOptions['ssl'])->toBe(['cafile' => '/tmp/ca.crt']);
});

test('sentinel.ssl accepts the stream wrapper', function () {
    $config = ['sentinel' => [
        'host' => '127.0.0.1', 'port' => 26379,
        'ssl' => ['stream' => ['cafile' => '/tmp/ca.crt']],
    ]];

    $connector = sslConnector()->withPhpRedisVersion('6.0.0');
    $connector->testConnectToSentinel($config);

    expect($connector->capturedOptions['ssl'])->toBe(['cafile' => '/tmp/ca.crt']);
});

test('sentinel.ssl accepts the ssl wrapper', function () {
    $config = ['sentinel' => [
        'host' => '127.0.0.1', 'port' => 26379,
        'ssl' => ['ssl' => ['cafile' => '/tmp/ca.crt']],
    ]];

    $connector = sslConnector()->withPhpRedisVersion('6.0.0');
    $connector->testConnectToSentinel($config);

    expect($connector->capturedOptions['ssl'])->toBe(['cafile' => '/tmp/ca.crt']);
});

test('no ssl option is added when sentinel.ssl is absent', function () {
    $config = ['sentinel' => ['host' => '127.0.0.1', 'port' => 26379]];

    $connector = sslConnector()->withPhpRedisVersion('6.0.0');
    $connector->testConnectToSentinel($config);

    expect($connector->capturedOptions)->not->toHaveKey('ssl');
});

test('sentinel.ssl is rejected on phpredis < 6 (no positional ssl argument)', function () {
    $config = ['sentinel' => [
        'host' => '127.0.0.1', 'port' => 26379,
        'ssl' => ['cafile' => '/tmp/ca.crt'],
    ]];

    $connector = sslConnector()->withPhpRedisVersion('5.3.9');

    expect(fn () => $connector->testConnectToSentinel($config))
        ->toThrow(ConfigurationException::class, 'phpredis >= 6.0');
});

test('a non-array sentinel.ssl is rejected', function () {
    $config = ['sentinel' => [
        'host' => '127.0.0.1', 'port' => 26379,
        'ssl' => 'yes',
    ]];

    $connector = sslConnector()->withPhpRedisVersion('6.0.0');

    expect(fn () => $connector->testConnectToSentinel($config))
        ->toThrow(ConfigurationException::class, 'must be an array');
});
