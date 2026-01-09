<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SessionController extends Controller
{
    /**
     * Store a value in the session
     */
    public function store(Request $request): JsonResponse
    {
        $key = $request->input('key');
        $value = $request->input('value');

        $request->session()->put($key, $value);

        return response()->json([
            'success' => true,
            'message' => 'Value stored in session',
        ]);
    }

    /**
     * Retrieve a value from the session
     */
    public function get(Request $request, string $key): JsonResponse
    {
        $value = $request->session()->get($key);

        return response()->json([
            'success' => true,
            'value' => $value,
        ]);
    }

    /**
     * Store multiple values in the session
     */
    public function storeMany(Request $request): JsonResponse
    {
        $data = $request->input('data', []);

        foreach ($data as $key => $value) {
            $request->session()->put($key, $value);
        }

        return response()->json([
            'success' => true,
            'message' => 'Values stored in session',
            'count' => count($data),
        ]);
    }

    /**
     * Flash a value to the session
     */
    public function flash(Request $request): JsonResponse
    {
        $key = $request->input('key');
        $value = $request->input('value');

        $request->session()->flash($key, $value);

        return response()->json([
            'success' => true,
            'message' => 'Value flashed to session',
        ]);
    }

    /**
     * Retrieve all session data
     */
    public function all(Request $request): JsonResponse
    {
        $data = $request->session()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Check if a key exists in the session
     */
    public function has(Request $request, string $key): JsonResponse
    {
        $exists = $request->session()->has($key);

        return response()->json([
            'success' => true,
            'exists' => $exists,
        ]);
    }

    /**
     * Remove a value from the session
     */
    public function forget(Request $request, string $key): JsonResponse
    {
        $request->session()->forget($key);

        return response()->json([
            'success' => true,
            'message' => 'Value removed from session',
        ]);
    }

    /**
     * Clear all session data
     */
    public function flush(Request $request): JsonResponse
    {
        $request->session()->flush();

        return response()->json([
            'success' => true,
            'message' => 'Session flushed',
        ]);
    }

    /**
     * Regenerate the session ID
     */
    public function regenerate(Request $request): JsonResponse
    {
        $oldId = $request->session()->getId();
        $request->session()->regenerate();
        $newId = $request->session()->getId();

        return response()->json([
            'success' => true,
            'message' => 'Session regenerated',
            'old_id' => $oldId,
            'new_id' => $newId,
        ]);
    }

    /**
     * Invalidate the session
     */
    public function invalidate(Request $request): JsonResponse
    {
        $request->session()->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Session invalidated',
        ]);
    }

    /**
     * Increment a counter in the session
     */
    public function increment(Request $request, string $key): JsonResponse
    {
        $value = $request->session()->get($key, 0);
        $value++;
        $request->session()->put($key, $value);

        return response()->json([
            'success' => true,
            'value' => $value,
        ]);
    }

    /**
     * Test session persistence by storing a value and retrieving it
     */
    public function testPersistence(Request $request): JsonResponse
    {
        $testKey = 'test_persistence_'.time();
        $testValue = 'test_value_'.rand(1000, 9999);

        $request->session()->put($testKey, $testValue);
        $retrieved = $request->session()->get($testKey);

        return response()->json([
            'success' => $retrieved === $testValue,
            'stored' => $testValue,
            'retrieved' => $retrieved,
            'session_id' => $request->session()->getId(),
        ]);
    }

    /**
     * Get session metadata
     */
    public function metadata(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'session_id' => $request->session()->getId(),
            'session_name' => $request->session()->getName(),
            'token' => $request->session()->token(),
        ]);
    }
}
