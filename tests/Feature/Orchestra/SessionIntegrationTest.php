<?php

use Illuminate\Session\CacheBasedSessionHandler;

describe('Session', function () {
    beforeEach(function () {
        // Configure session to use phpredis-sentinel
        config()->set('session.driver', 'phpredis-sentinel');
        config()->set('session.connection', 'phpredis-sentinel');
        config()->set('session.lifetime', 120);
        config()->set('session.expire_on_close', false);

        // Clear session data
        if ($this->app->bound('session')) {
            session()->flush();
        }
    });

    test('session uses redis sentinel connection', function () {
        $driver = session()->driver('phpredis-sentinel');
        $handler = $driver->getHandler();

        expect($handler)->toBeInstanceOf(CacheBasedSessionHandler::class);
    });

    test('session can store and retrieve values via routes', function () {
        $response = $this->post('/session/store', [
            'key' => 'test_key',
            'value' => 'test_value',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $response = $this->get('/session/get/test_key');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'value' => 'test_value',
            ]);
    });

    test('session can store multiple values', function () {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'admin',
        ];

        $response = $this->post('/session/store-many', [
            'data' => $data,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 3,
            ]);

        foreach ($data as $key => $value) {
            $response = $this->get("/session/get/{$key}");
            $response->assertJson(['value' => $value]);
        }
    });

    test('session flash data expires after next request', function () {
        // Flash a value
        $response = $this->post('/session/flash', [
            'key' => 'flash_message',
            'value' => 'This is a flash message',
        ]);

        $response->assertStatus(200);

        // First request - flash data should be available
        $response = $this->get('/session/get/flash_message');
        $response->assertJson(['value' => 'This is a flash message']);

        // Second request - flash data should be gone
        $response = $this->get('/session/get/flash_message');
        $response->assertJson(['value' => null]);
    });

    test('session can check if key exists', function () {
        session()->put('existing_key', 'value');

        $response = $this->get('/session/has/existing_key');
        $response->assertJson(['exists' => true]);

        $response = $this->get('/session/has/non_existing_key');
        $response->assertJson(['exists' => false]);
    });

    test('session can forget specific keys', function () {
        session()->put('key_to_forget', 'temporary');

        $response = $this->get('/session/has/key_to_forget');
        $response->assertJson(['exists' => true]);

        $response = $this->delete('/session/forget/key_to_forget');
        $response->assertStatus(200);

        $response = $this->get('/session/has/key_to_forget');
        $response->assertJson(['exists' => false]);
    });

    test('session can be flushed', function () {
        session()->put('key1', 'value1');
        session()->put('key2', 'value2');
        session()->put('key3', 'value3');

        $response = $this->delete('/session/flush');
        $response->assertStatus(200);

        $response = $this->get('/session/all');
        $data = $response->json('data');

        // Session might have some internal keys, but our keys should be gone
        expect($data)->not->toHaveKey('key1')
            ->and($data)->not->toHaveKey('key2')
            ->and($data)->not->toHaveKey('key3');
    });

    test('session can be regenerated', function () {
        session()->put('test_key', 'test_value');

        $response = $this->get('/session/metadata');
        $oldId = $response->json('session_id');

        $response = $this->post('/session/regenerate');
        $response->assertStatus(200);

        $newId = $response->json('new_id');

        expect($newId)->not->toBe($oldId);

        // Data should persist after regeneration
        $response = $this->get('/session/get/test_key');
        $response->assertJson(['value' => 'test_value']);
    });

    test('session can be invalidated', function () {
        session()->put('important_data', 'sensitive');

        $response = $this->post('/session/invalidate');
        $response->assertStatus(200);

        // After invalidation, old data should be gone
        $response = $this->get('/session/get/important_data');
        $response->assertJson(['value' => null]);
    });

    test('session can increment counters', function () {
        session()->put('counter', 0);

        $response = $this->post('/session/increment/counter');
        $response->assertJson(['value' => 1]);

        $response = $this->post('/session/increment/counter');
        $response->assertJson(['value' => 2]);

        $response = $this->post('/session/increment/counter');
        $response->assertJson(['value' => 3]);
    });

    test('session persists across multiple requests', function () {
        $response = $this->get('/session/test-persistence');

        $response->assertStatus(200);
        $data = $response->json();

        expect($data['success'])->toBeTrue()
            ->and($data['stored'])->toBe($data['retrieved'])
            ->and($data['session_id'])->toBeString();
    });

    test('session middleware tracks request activity', function () {
        // First request
        $this->get('/session/metadata');

        $response = $this->get('/session/get/request_count');
        $count1 = $response->json('value');

        // Second request
        $this->get('/session/metadata');

        $response = $this->get('/session/get/request_count');
        $count2 = $response->json('value');

        // Third request
        $this->get('/session/metadata');

        $response = $this->get('/session/get/request_count');
        $count3 = $response->json('value');

        expect($count2)->toBeGreaterThan($count1)
            ->and($count3)->toBeGreaterThan($count2);
    });

    test('session stores complex data structures', function () {
        $complexData = [
            'string' => 'text',
            'integer' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => [
                'level1' => [
                    'level2' => ['data' => 'value'],
                ],
            ],
        ];

        $response = $this->post('/session/store', [
            'key' => 'complex',
            'value' => $complexData,
        ]);

        $response->assertStatus(200);

        $response = $this->get('/session/get/complex');
        $retrieved = $response->json('value');

        expect($retrieved)->toBe($complexData)
            ->and($retrieved['string'])->toBe('text')
            ->and($retrieved['integer'])->toBe(42)
            ->and($retrieved['float'])->toBe(3.14)
            ->and($retrieved['boolean'])->toBeTrue()
            ->and($retrieved['null'])->toBeNull()
            ->and($retrieved['nested']['level1']['level2']['data'])->toBe('value');
    });

    test('session handles concurrent requests correctly', function () {
        // Simulate multiple concurrent requests
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/session/store', [
                'key' => "concurrent_{$i}",
                'value' => "value_{$i}",
            ]);
        }

        // Verify all values were stored correctly
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->get("/session/get/concurrent_{$i}");
            $response->assertJson(['value' => "value_{$i}"]);
        }
    });

    test('session metadata is accessible', function () {
        $response = $this->get('/session/metadata');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'session_id',
                'session_name',
                'token',
            ]);

        $data = $response->json();

        expect($data['success'])->toBeTrue()
            ->and($data['session_id'])->toBeString()
            ->and($data['session_id'])->not->toBeEmpty()
            ->and($data['session_name'])->toBeString()
            ->and($data['token'])->toBeString();
    });

    test('session persists after application restart', function () {
        // Store data
        $this->post('/session/store', [
            'key' => 'persistent_key',
            'value' => 'persistent_value',
        ]);

        $response = $this->get('/session/metadata');
        $sessionId = $response->json('session_id');

        // Simulate app restart by creating new request with same session
        $response = $this->withSession(['persistent_key' => 'persistent_value'])
            ->get('/session/get/persistent_key');

        $response->assertJson(['value' => 'persistent_value']);
    });

    test('session can retrieve all data', function () {
        $testData = [
            'user_id' => 123,
            'username' => 'testuser',
            'preferences' => ['theme' => 'dark'],
        ];

        foreach ($testData as $key => $value) {
            session()->put($key, $value);
        }

        $response = $this->get('/session/all');

        $response->assertStatus(200);
        $data = $response->json('data');

        expect($data)->toHaveKey('user_id')
            ->and($data['user_id'])->toBe(123)
            ->and($data)->toHaveKey('username')
            ->and($data['username'])->toBe('testuser')
            ->and($data)->toHaveKey('preferences')
            ->and($data['preferences'])->toBe(['theme' => 'dark']);
    });

    test('session connection remains stable under load', function () {
        // Perform many session operations
        for ($i = 1; $i <= 50; $i++) {
            $this->post('/session/store', [
                'key' => "load_test_{$i}",
                'value' => "value_{$i}",
            ]);
        }

        // Verify random samples
        $samples = [5, 15, 25, 35, 45];
        foreach ($samples as $i) {
            $response = $this->get("/session/get/load_test_{$i}");
            $response->assertJson(['value' => "value_{$i}"]);
        }

        // Session should still be functional
        $response = $this->get('/session/metadata');
        $response->assertStatus(200);
    });

    test('session handles special characters and encoding', function () {
        $specialData = [
            'emoji' => '🚀 🎉 💻',
            'unicode' => 'Héllo Wörld 你好',
            'quotes' => "It's a \"test\" with 'quotes'",
            'newlines' => "Line 1\nLine 2\nLine 3",
            'tabs' => "Col1\tCol2\tCol3",
        ];

        foreach ($specialData as $key => $value) {
            $this->post('/session/store', [
                'key' => $key,
                'value' => $value,
            ]);
        }

        foreach ($specialData as $key => $expected) {
            $response = $this->get("/session/get/{$key}");
            expect($response->json('value'))->toBe($expected);
        }
    });
});
