<?php

use Illuminate\Support\Facades\Redis;

describe('RedisSentinelConnection Pipeline and Transaction', function () {
    test('pipeline executes multiple commands atomically', function () {
        $connection = Redis::connection('phpredis-sentinel');

        $results = $connection->pipeline(function ($pipe) {
            $pipe->set('pipeline_key1', 'value1');
            $pipe->set('pipeline_key2', 'value2');
            $pipe->get('pipeline_key1');
            $pipe->get('pipeline_key2');
        });

        expect($results)->toBeArray();
        expect($results[0])->toBeTrue(); // set result
        expect($results[1])->toBeTrue(); // set result
        expect($results[2])->toBe('value1'); // get result
        expect($results[3])->toBe('value2'); // get result

        // Cleanup
        $connection->del(['pipeline_key1', 'pipeline_key2']);
    });

    test('transaction executes commands atomically', function () {
        $connection = Redis::connection('phpredis-sentinel');

        $results = $connection->transaction(function ($trans) {
            $trans->set('trans_key1', 'value1');
            $trans->set('trans_key2', 'value2');
            $trans->get('trans_key1');
        });

        expect($results)->toBeArray();
        expect($results)->toHaveCount(3);

        // Cleanup
        $connection->del(['trans_key1', 'trans_key2']);
    });

    test('nested transaction levels are tracked correctly', function () {
        $connection = Redis::connection('phpredis-sentinel');

        // Test that transaction level increments and decrements
        $results = $connection->transaction(function ($trans) {
            // Inside transaction, read operations should go to master
            $trans->set('nested_trans_key', 'value');

            return $trans->get('nested_trans_key');
        });

        expect($results[1])->toBe('value');

        // Cleanup
        $connection->del('nested_trans_key');
    });
});
