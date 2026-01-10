<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

describe('Session E2E Tests WITHOUT Read/Write Splitting - Master Only', function () {
    beforeEach(function () {
        // Configure WITHOUT read/write splitting (master only mode)
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', false);
        config()->set('session.driver', 'redis');
        config()->set('session.connection', 'phpredis-sentinel');
        config()->set('session.store', 'phpredis-sentinel');

        // Purge connections
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Fresh session
        Session::flush();
        Session::regenerate();
    });

    afterEach(function () {
        Session::flush();
    });

    test('session operations in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $readConnectorProp = $reflection->getProperty('readConnector');
        $readConnectorProp->setAccessible(true);

        // No read connector in master-only mode
        expect($readConnectorProp->getValue($connection))->toBeNull('No read connector in master-only mode');

        // All session operations go to master
        Session::put('test_key', 'test_value');
        Session::save();

        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');
        $wroteToMasterProp->setAccessible(true);
        expect($wroteToMasterProp->getValue($connection))->toBeTrue('All operations use master');
    });

    test('session stores and retrieves in master-only mode', function () {
        $testId = 'session_master_'.time();

        // Store session data
        Session::put('user_id', 54321);
        Session::put('username', 'masteruser');
        Session::put('settings', [
            'theme' => 'light',
            'lang' => 'fr',
            'notifications' => false,
        ]);
        Session::put('test_id', $testId);
        Session::save();

        // Retrieve and verify
        expect(Session::get('user_id'))->toBe(54321)
            ->and(Session::get('username'))->toBe('masteruser')
            ->and(Session::get('settings'))->toBe([
                'theme' => 'light',
                'lang' => 'fr',
                'notifications' => false,
            ]);
    });

    test('session persists through connection reset in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store data
        Session::put('user_id', 88888);
        Session::put('cart', ['product1', 'product2', 'product3']);
        Session::put('last_page', '/checkout');
        Session::save();

        expect(Session::get('user_id'))->toBe(88888);

        // Disconnect
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Verify persistence
        expect(Session::get('user_id'))->toBe(88888)
            ->and(Session::get('cart'))->toBe(['product1', 'product2', 'product3'])
            ->and(Session::get('last_page'))->toBe('/checkout');
    });

    test('session handles failover during active usage in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Phase 1
        Session::put('step', 'start');
        Session::put('user_id', 6000);
        Session::put('start_time', now()->timestamp);
        Session::save();

        expect(Session::get('step'))->toBe('start');

        // Phase 2
        Session::put('step', 'processing');
        Session::put('items_viewed', [201, 202, 203, 204]);
        Session::save();

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(2);

        // Phase 3 - after failover
        Session::put('step', 'complete');
        Session::put('order_total', 599.99);
        Session::save();

        // Verify all data
        expect(Session::get('user_id'))->toBe(6000)
            ->and(Session::get('items_viewed'))->toBe([201, 202, 203, 204])
            ->and(Session::get('step'))->toBe('complete')
            ->and(Session::get('order_total'))->toBe(599.99);
    });

    test('session flash data in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Flash data
        Session::flash('message', 'Task completed');
        Session::flash('alert', 'Warning: System update');
        Session::save();

        // Verify
        expect(Session::get('message'))->toBe('Task completed')
            ->and(Session::get('alert'))->toBe('Warning: System update');

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Should still be available
        expect(Session::get('message'))->toBe('Task completed');

        // Age flash
        Session::ageFlashData();
        Session::save();

        expect(Session::get('message'))->toBeNull();
    });

    test('session multiple updates during failover in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $updates = 70;

        for ($i = 1; $i <= $updates; $i++) {
            try {
                if ($i === 35) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                Session::put("data_{$i}", [
                    'timestamp' => now()->timestamp,
                    'value' => "content_{$i}",
                ]);
                Session::increment('update_counter', 1);
                Session::save();
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        // Verify
        $successful = 0;
        for ($i = 1; $i <= $updates; $i++) {
            if (Session::has("data_{$i}")) {
                $successful++;
            }
        }

        expect($successful)->toBeGreaterThan(65);

        $counter = Session::get('update_counter', 0);
        expect($counter)->toBeGreaterThan(65);
    });

    test('session regenerate in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store data
        Session::put('user_id', 9999);
        Session::put('role', 'user');
        Session::save();

        $firstId = Session::getId();

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Regenerate
        Session::regenerate();
        $secondId = Session::getId();

        expect($secondId)->not->toBe($firstId);
        expect(Session::get('user_id'))->toBe(9999)
            ->and(Session::get('role'))->toBe('user');
    });

    test('session forget and pull in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store
        Session::put('temp1', 'value1');
        Session::put('temp2', 'value2');
        Session::put('permanent', 'keep_this');
        Session::save();

        expect(Session::has('temp1'))->toBeTrue();

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Forget
        Session::forget('temp1');
        Session::save();

        expect(Session::has('temp1'))->toBeFalse();

        // Pull
        $value = Session::pull('temp2');
        Session::save();

        expect($value)->toBe('value2')
            ->and(Session::has('temp2'))->toBeFalse()
            ->and(Session::get('permanent'))->toBe('keep_this');
    });

    test('session increment and decrement in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        Session::put('views', 0);
        Session::put('items', 20);
        Session::save();

        // Operations with failover
        for ($i = 1; $i <= 30; $i++) {
            try {
                if ($i === 15) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(1);
                }

                Session::increment('views');
                if ($i % 2 === 0) {
                    Session::decrement('items');
                }
                Session::save();
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        $views = Session::get('views', 0);
        $items = Session::get('items', 20);

        expect($views)->toBeGreaterThan(25)
            ->and($items)->toBeLessThan(10);
    });

    test('session array operations in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        Session::put('cart', []);
        Session::save();

        // Add items
        Session::push('cart', ['id' => 10, 'name' => 'Item 10']);
        Session::push('cart', ['id' => 11, 'name' => 'Item 11']);
        Session::save();

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Add more
        Session::push('cart', ['id' => 12, 'name' => 'Item 12']);
        Session::save();

        $cart = Session::get('cart');
        expect($cart)->toHaveCount(3)
            ->and($cart[0]['id'])->toBe(10)
            ->and($cart[2]['id'])->toBe(12);
    });

    test('session token in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        $token = Session::token();
        Session::save();

        expect($token)->toBeString();

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        $retrievedToken = Session::token();
        expect($retrievedToken)->toBe($token);
    });

    test('session high load in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $ops = 150;
        $successful = 0;

        for ($i = 1; $i <= $ops; $i++) {
            try {
                if ($i % 30 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000);
                }

                // Mixed operations
                if ($i % 4 === 0) {
                    Session::put("key_{$i}", "val_{$i}");
                } elseif ($i % 4 === 1) {
                    Session::increment('counter');
                } elseif ($i % 4 === 2) {
                    Session::push('log', $i);
                } else {
                    Session::flash("flash_{$i}", "msg_{$i}");
                }

                Session::save();
                $successful++;
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        expect($successful)->toBeGreaterThan(140);
    });

    test('session form data in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store form data
        Session::put('_old_input', [
            'email' => 'user@example.com',
            'name' => 'Test User',
            'preferences' => ['marketing' => false],
        ]);
        Session::save();

        $oldInput = Session::get('_old_input');
        expect($oldInput['email'])->toBe('user@example.com');

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        $oldInput = Session::get('_old_input');
        expect($oldInput['email'])->toBe('user@example.com')
            ->and($oldInput['preferences'])->toBe(['marketing' => false]);
    });

    test('session survives multiple failovers in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        Session::put('persistent_id', 999999);
        Session::put('role', 'admin');
        Session::save();

        // Multiple failovers
        for ($round = 1; $round <= 4; $round++) {
            expect(Session::get('persistent_id'))->toBe(999999)
                ->and(Session::get('role'))->toBe('admin');

            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Expected
            }

            sleep(1);

            Session::put("failover_{$round}", now()->timestamp);
            Session::save();
        }

        // Verify all
        expect(Session::get('persistent_id'))->toBe(999999)
            ->and(Session::has('failover_1'))->toBeTrue()
            ->and(Session::has('failover_2'))->toBeTrue()
            ->and(Session::has('failover_3'))->toBeTrue()
            ->and(Session::has('failover_4'))->toBeTrue();
    });

    test('session isolation in master-only mode', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Session 1
        $session1Id = Session::getId();
        Session::put('user_id', 1111);
        Session::put('type', 'session1');
        Session::save();

        // New session
        Session::flush();
        Session::regenerate(true);

        // Session 2
        $session2Id = Session::getId();
        Session::put('user_id', 2222);
        Session::put('type', 'session2');
        Session::save();

        expect($session2Id)->not->toBe($session1Id)
            ->and(Session::get('user_id'))->toBe(2222)
            ->and(Session::get('type'))->toBe('session2');

        // Failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Still isolated
        expect(Session::get('user_id'))->toBe(2222)
            ->and(Session::get('type'))->toBe('session2');
    });
});
