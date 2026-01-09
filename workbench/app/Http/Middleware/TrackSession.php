<?php

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackSession
{
    /**
     * Handle an incoming request and track session activity.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Track the number of requests in this session
        $count = $request->session()->get('request_count', 0);
        $request->session()->put('request_count', $count + 1);

        // Track the last request time
        $request->session()->put('last_request_time', now()->timestamp);

        // Track the request path
        $paths = $request->session()->get('request_paths', []);
        $paths[] = $request->path();
        $request->session()->put('request_paths', $paths);

        return $next($request);
    }
}
