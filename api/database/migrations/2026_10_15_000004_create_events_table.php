<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->jsonb('headers');
            // Raw body exactly as received. Non-UTF-8 payloads are stored base64-encoded
            // (body_encoding = 'base64') because Postgres `text` must be valid UTF-8.
            // TODO(object-storage): move large bodies to S3-compatible storage and keep a pointer here.
            $table->text('body');
            $table->string('body_encoding', 16)->default('utf8');
            $table->string('content_type')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestampTz('received_at')->useCurrent();

            $table->unique(['source_id', 'idempotency_key']);
            $table->index(['source_id', 'received_at']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
