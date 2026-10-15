<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Ingestion rate limit (requests/second, burst = same). Null = RELAY_INGEST_DEFAULT_RPS.
            $table->unsignedInteger('rate_limit_rps')->nullable()->after('dedupe_window_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('rate_limit_rps'));
    }
};
