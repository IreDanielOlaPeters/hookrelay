<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            // admin: full access (created with `php artisan relay:admin`).
            // demo:  browse, send test webhooks, replay; no deletes or settings (DEMO_MODE only).
            // TODO(multi-tenancy): roles per tenant.
            $table->enum('role', ['admin', 'demo'])->default('admin');
            $table->timestamps();
        });

        // Server-side dashboard sessions. Only a SHA-256 of the token is stored.
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');
    }
};
