<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the management API with a single static bearer token (ADMIN_TOKEN).
 * TODO(auth): swap for real users / API keys (e.g. Sanctum) when multi-user support lands.
 */
class AdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('relay.admin_token');
        if ($expected === '') {
            return response()->json(['message' => 'ADMIN_TOKEN is not configured.'], 503);
        }

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
