<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable(); // truncated to 4 KB
            $table->text('error')->nullable();

            $table->index(['delivery_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
