<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('auth_header')->nullable(); // encrypted cast
            $table->unsignedInteger('timeout_ms')->default(10000);
            $table->unsignedInteger('rate_limit_rps')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(6);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
