<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class UserSession extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'last_used_at', 'user_agent'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Start a session and return the plain token (shown once, never stored).
     *
     * @return array{0: self, 1: string}
     */
    public static function start(User $user, ?string $userAgent = null): array
    {
        $token = Str::random(64);
        $session = self::create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'expires_at' => Carbon::now()->addMinutes((int) config('relay.session_minutes')),
            'last_used_at' => Carbon::now(),
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ]);

        return [$session, $token];
    }

    public static function findValid(string $token): ?self
    {
        return self::query()
            ->with('user')
            ->where('token_hash', self::hash($token))
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
