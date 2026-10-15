<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Dashboard authentication. The dashboard posts credentials here server-side, keeps the
 * returned token in an httpOnly cookie, and sends it as a bearer token on every call.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', strtolower(trim($request->validated('email'))))->first();

        // Check against a dummy hash when the user is unknown, so timing doesn't reveal which emails exist.
        static $dummyHash;
        $dummyHash ??= Hash::make('hookrelay-dummy-password');
        $valid = Hash::check($request->validated('password'), $user?->password ?? $dummyHash);

        if (! $user || ! $valid || ($user->isDemo() && ! config('relay.demo.enabled'))) {
            return response()->json(['message' => 'These credentials do not match our records.'], 422);
        }

        [$session, $token] = UserSession::start($user, $request->userAgent());

        return response()->json([
            'token' => $token,
            'expires_at' => $session->expires_at,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
            'demo_mode' => (bool) config('relay.demo.enabled'),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->attributes->get('relay_session')?->delete();

        return response()->noContent();
    }

    /** Public demo credentials for the login page. Empty unless DEMO_MODE=true. */
    public function demo(): JsonResponse
    {
        if (! config('relay.demo.enabled')) {
            return response()->json(['demo_mode' => false]);
        }

        return response()->json([
            'demo_mode' => true,
            'email' => config('relay.demo.email'),
            'password' => config('relay.demo.password'),
        ]);
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role];
    }
}
