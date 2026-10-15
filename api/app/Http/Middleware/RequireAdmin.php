<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Deletes and settings changes: admins only. The demo user gets a 403 with a clear message. */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Not available to the demo user. Data and settings are read-only in demo mode.'], 403);
        }

        return $next($request);
    }
}
