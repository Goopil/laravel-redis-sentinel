<?php

use Goopil\LaravelRedisSentinel\Connections\RedisSentinelConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

describe('Session E2E Failover Tests with Read/Write Mode', function () {
    beforeEach(function () {
        // Configure read/write splitting for sessions BEFORE operations
        config()->set('database.redis.phpredis-sentinel.read_only_replicas', true);
        config()->set('session.driver', 'redis');
        config()->set('session.connection', 'phpredis-sentinel');
        config()->set('session.store', 'phpredis-sentinel');

        // Purge connections
        $manager = app(\Goopil\LaravelRedisSentinel\RedisSentinelManager::class);

        $reflection = new ReflectionClass($manager);
        $configProp = $reflection->getProperty('config');
        $configProp->setValue($manager, config('database.redis'));

        $manager->purge('phpredis-sentinel');

        // Start fresh session
        try {
            Session::flush();
            Session::regenerate();
        } catch (\Exception $e) {
            // Ignore errors in setup
        }
    });

    afterEach(function () {
        Session::flush();
    });

    test('session operations use read/write splitting correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        if (! $connection instanceof RedisSentinelConnection) {
            $this->markTestSkipped('Not using RedisSentinelConnection');
        }

        $reflection = new ReflectionClass($connection);
        $wroteToMasterProp = $reflection->getProperty('wroteToMaster');

        // Session read should not trigger stickiness initially
        Session::get('test_key');
        expect($wroteToMasterProp->getValue($connection))->toBeFalse('Session read should not trigger stickiness');

        // Session write should trigger stickiness
        Session::put('test_key', 'test_value');
        Session::save(); // Force write to Redis

        expect($wroteToMasterProp->getValue($connection))->toBeTrue('Session write should trigger stickiness');
    });

    test('session stores and retrieves data with read/write mode', function () {
        $testId = 'session_rw_'.time();

        // Store session data
        Session::put('user_id', 12345);
        Session::put('username', 'testuser');
        Session::put('preferences', [
            'theme' => 'dark',
            'language' => 'en',
            'notifications' => true,
        ]);
        Session::put('test_id', $testId);

        Session::save();

        // Retrieve and verify
        expect(Session::get('user_id'))->toBe(12345)
            ->and(Session::get('username'))->toBe('testuser')
            ->and(Session::get('preferences'))->toBe([
                'theme' => 'dark',
                'language' => 'en',
                'notifications' => true,
            ])
            ->and(Session::get('test_id'))->toBe($testId);
    });

    test('session data persists through connection reset', function () {
        $sessionId = Session::getId();
        $connection = Redis::connection('phpredis-sentinel');

        // Store session data
        Session::put('user_id', 99999);
        Session::put('cart_items', ['item1', 'item2', 'item3']);
        Session::put('last_activity', now()->timestamp);
        Session::save();

        // Verify data exists
        expect(Session::get('user_id'))->toBe(99999)
            ->and(Session::get('cart_items'))->toBe(['item1', 'item2', 'item3']);

        // Force disconnection
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Data should persist after reconnection
        expect(Session::get('user_id'))->toBe(99999)
            ->and(Session::get('cart_items'))->toBe(['item1', 'item2', 'item3']);
    });

    test('session handles failover during active user session', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $sessionId = Session::getId();

        // Simulate user activity - Phase 1
        Session::put('step', 'login');
        Session::put('user_id', 5000);
        Session::put('login_time', now()->timestamp);
        Session::save();

        expect(Session::get('step'))->toBe('login');

        // More user activity
        Session::put('step', 'browsing');
        Session::put('viewed_products', [101, 102, 103]);
        Session::save();

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(2); // Failover window

        // Continue user activity after failover
        Session::put('step', 'checkout');
        Session::put('cart_total', 299.99);
        Session::save();

        // Verify all data persisted through failover
        expect(Session::get('user_id'))->toBe(5000)
            ->and(Session::get('viewed_products'))->toBe([101, 102, 103])
            ->and(Session::get('step'))->toBe('checkout')
            ->and(Session::get('cart_total'))->toBe(299.99);
    });

    test('session flash data works through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Flash data for next request
        Session::flash('success', 'Operation completed successfully');
        Session::flash('notification', 'You have 3 new messages');
        Session::save();

        // Verify flash data exists
        expect(Session::get('success'))->toBe('Operation completed successfully')
            ->and(Session::get('notification'))->toBe('You have 3 new messages');

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Flash data should still be available (this is the "next" request)
        expect(Session::get('success'))->toBe('Operation completed successfully')
            ->and(Session::get('notification'))->toBe('You have 3 new messages');

        // After this request, flash data should be gone
        Session::ageFlashData();
        Session::save();

        expect(Session::get('success'))->toBeNull()
            ->and(Session::get('notification'))->toBeNull();
    });

    test('session handles multiple concurrent updates during failover', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $sessionId = Session::getId();
        $updateCount = 50;

        for ($i = 1; $i <= $updateCount; $i++) {
            try {
                // Simulate failover at midpoint
                if ($i === 25) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(2);
                }

                // Update session with new data
                Session::put("update_{$i}", [
                    'timestamp' => now()->timestamp,
                    'value' => "data_{$i}",
                    'index' => $i,
                ]);

                Session::increment('update_count', 1);
                Session::save();
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        // Verify most updates succeeded
        $successfulUpdates = 0;
        for ($i = 1; $i <= $updateCount; $i++) {
            if (Session::has("update_{$i}")) {
                $successfulUpdates++;
            }
        }

        expect($successfulUpdates)->toBeGreaterThan(45, 'Most session updates should succeed');

        $updateCount = Session::get('update_count', 0);
        expect($updateCount)->toBeGreaterThan(45, 'Update counter should reflect most operations');
    });

    test('session regenerate works through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store data in first session
        Session::put('user_id', 7777);
        Session::put('role', 'admin');
        Session::save();

        $firstSessionId = Session::getId();

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Regenerate session after failover
        Session::regenerate();
        $secondSessionId = Session::getId();

        expect($secondSessionId)->not->toBe($firstSessionId, 'Session ID should change');

        // Data should be migrated to new session
        expect(Session::get('user_id'))->toBe(7777)
            ->and(Session::get('role'))->toBe('admin');
    });

    test('session forget and pull work correctly during failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store data
        Session::put('temp_data_1', 'value_1');
        Session::put('temp_data_2', 'value_2');
        Session::put('persistent_data', 'persistent_value');
        Session::save();

        // Verify all exist
        expect(Session::has('temp_data_1'))->toBeTrue()
            ->and(Session::has('temp_data_2'))->toBeTrue()
            ->and(Session::has('persistent_data'))->toBeTrue();

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Forget one key
        Session::forget('temp_data_1');
        Session::save();

        expect(Session::has('temp_data_1'))->toBeFalse('Forgotten key should not exist');

        // Pull another key (get and delete)
        $pulledValue = Session::pull('temp_data_2');
        Session::save();

        expect($pulledValue)->toBe('value_2')
            ->and(Session::has('temp_data_2'))->toBeFalse('Pulled key should not exist')
            ->and(Session::get('persistent_data'))->toBe('persistent_value');
    });

    test('session increment and decrement work during failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Initialize counters
        Session::put('page_views', 0);
        Session::put('cart_items', 10);
        Session::save();

        // Increment/decrement with failover
        for ($i = 1; $i <= 20; $i++) {
            try {
                if ($i === 10) {
                    // Simulate failover
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    sleep(1);
                }

                Session::increment('page_views');
                if ($i % 2 === 0) {
                    Session::decrement('cart_items');
                }
                Session::save();
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        // Verify counters
        $pageViews = Session::get('page_views', 0);
        $cartItems = Session::get('cart_items', 10);

        expect($pageViews)->toBeGreaterThan(15, 'Page views should be incremented')
            ->and($cartItems)->toBeLessThan(5, 'Cart items should be decremented');
    });

    test('session array operations work through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Initialize array
        Session::put('shopping_cart', []);
        Session::save();

        // Add items
        Session::push('shopping_cart', ['id' => 1, 'name' => 'Product 1']);
        Session::push('shopping_cart', ['id' => 2, 'name' => 'Product 2']);
        Session::save();

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Continue adding items after failover
        Session::push('shopping_cart', ['id' => 3, 'name' => 'Product 3']);
        Session::save();

        $cart = Session::get('shopping_cart');

        expect($cart)->toBeArray()
            ->and($cart)->toHaveCount(3)
            ->and($cart[0]['id'])->toBe(1)
            ->and($cart[2]['id'])->toBe(3);
    });

    test('session token and csrf work through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Generate token
        $token = Session::token();
        Session::save();

        expect($token)->toBeString()
            ->and(strlen($token))->toBeGreaterThan(0);

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Token should persist
        $retrievedToken = Session::token();
        expect($retrievedToken)->toBe($token, 'Token should persist through failover');
    });

    test('session handling during high load with failover', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $operationCount = 100;
        $successfulOps = 0;

        for ($i = 1; $i <= $operationCount; $i++) {
            try {
                // Trigger multiple failovers
                if ($i % 20 === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Exception $e) {
                        // Expected
                    }
                    usleep(500000); // 500ms
                }

                // Mix of operations
                if ($i % 4 === 0) {
                    Session::put("key_{$i}", "value_{$i}");
                } elseif ($i % 4 === 1) {
                    Session::increment('operation_counter');
                } elseif ($i % 4 === 2) {
                    Session::push('activity_log', $i);
                } else {
                    Session::flash("flash_{$i}", "message_{$i}");
                }

                Session::save();
                $successfulOps++;
            } catch (\Exception $e) {
                // Expected during failover
            }
        }

        expect($successfulOps)->toBeGreaterThan(90, 'Most operations should succeed during high load');
    });

    test('session form input data persists through failover', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Store form input data (common pattern for form handling)
        Session::put('_old_input', [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'preferences' => ['newsletter' => true],
        ]);
        Session::save();

        // Verify input data
        $oldInput = Session::get('_old_input');
        expect($oldInput)->toBeArray()
            ->and($oldInput['username'])->toBe('testuser')
            ->and($oldInput['email'])->toBe('test@example.com');

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Input data should persist
        $oldInput = Session::get('_old_input');
        expect($oldInput['username'])->toBe('testuser')
            ->and($oldInput['email'])->toBe('test@example.com')
            ->and($oldInput['preferences'])->toBe(['newsletter' => true]);
    });

    test('session with long expiration survives multiple failovers', function () {
        $connection = Redis::connection('phpredis-sentinel');
        $sessionId = Session::getId();

        // Store persistent session data
        Session::put('persistent_user_id', 123456);
        Session::put('subscription', 'premium');
        Session::save();

        // Simulate multiple failovers
        for ($round = 1; $round <= 3; $round++) {
            // Verify data before failover
            expect(Session::get('persistent_user_id'))->toBe(123456)
                ->and(Session::get('subscription'))->toBe('premium');

            // Failover
            try {
                $connection->disconnect();
            } catch (\Exception $e) {
                // Expected
            }

            sleep(1);

            // Update data after failover
            Session::put("failover_round_{$round}", now()->timestamp);
            Session::save();
        }

        // Verify all data persisted
        expect(Session::get('persistent_user_id'))->toBe(123456)
            ->and(Session::get('subscription'))->toBe('premium')
            ->and(Session::has('failover_round_1'))->toBeTrue()
            ->and(Session::has('failover_round_2'))->toBeTrue()
            ->and(Session::has('failover_round_3'))->toBeTrue();
    });

    test('session maintains isolation between different session ids', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Session 1
        $session1Id = Session::getId();
        Session::put('user_id', 1000);
        Session::put('session_type', 'session_1');
        Session::save();

        $session1Data = [
            'user_id' => Session::get('user_id'),
            'session_type' => Session::get('session_type'),
        ];

        // Create new session
        Session::flush();
        Session::regenerate(true);

        // Session 2
        $session2Id = Session::getId();
        Session::put('user_id', 2000);
        Session::put('session_type', 'session_2');
        Session::save();

        expect($session2Id)->not->toBe($session1Id, 'Session IDs should be different')
            ->and(Session::get('user_id'))->toBe(2000)
            ->and(Session::get('session_type'))->toBe('session_2');

        // Simulate failover
        try {
            $connection->disconnect();
        } catch (\Exception $e) {
            // Expected
        }

        sleep(1);

        // Sessions should remain isolated after failover
        expect(Session::get('user_id'))->toBe(2000)
            ->and(Session::get('session_type'))->toBe('session_2');
    });
});
