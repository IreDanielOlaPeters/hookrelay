<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'delivering', 'succeeded', 'failed', 'dead'])->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['destination_id', 'status']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
