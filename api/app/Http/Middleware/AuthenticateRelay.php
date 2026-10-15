<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates /api/* requests with either:
 *  - the static ADMIN_TOKEN (scripts, automation): acts as an admin; or
 *  - a dashboard session token from POST /api/auth/login: acts as that user.
 * TODO(multi-tenancy): resolve the tenant from the principal here.
 */
class AuthenticateRelay
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '') {
            return $this->unauthenticated();
        }

        $adminToken = (string) config('relay.admin_token');
        if ($adminToken !== '' && hash_equals($adminToken, $token)) {
            $user = User::apiToken();
        } else {
            $session = UserSession::findValid($token);
            if (! $session || ! $session->user) {
                return $this->unauthenticated();
            }
            // Record activity at most once a minute to keep reads cheap.
            if (! $session->last_used_at || $session->last_used_at->lt(Carbon::now()->subMinute())) {
                $session->forceFill(['last_used_at' => Carbon::now()])->save();
            }
            $user = $session->user;
            $request->attributes->set('relay_session', $session);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
