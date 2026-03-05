<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyExtensionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.extension.token');

        // If no token is configured, block all requests (fail-secure)
        if (empty($expected)) {
            return response()->json(['error' => 'Extension token not configured.'], 403);
        }

        $provided = $request->header('X-Extension-Token')
            ?? $request->query('token');

        if (!hash_equals($expected, (string) $provided)) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        return $next($request);
    }
}
