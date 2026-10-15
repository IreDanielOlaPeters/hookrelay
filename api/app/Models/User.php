<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_DEMO = 'demo';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDemo(): bool
    {
        return $this->role === self::ROLE_DEMO;
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** The principal used for requests authenticated with the static ADMIN_TOKEN. */
    public static function apiToken(): self
    {
        $user = new self(['name' => 'API token', 'email' => 'api-token@hookrelay.local']);
        $user->role = self::ROLE_ADMIN;

        return $user;
    }
}
