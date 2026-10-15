<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TODO(multi-tenancy): add tenant_id to sources/destinations and scope slugs per tenant.
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('provider', ['generic', 'stripe', 'github', 'shopify', 'woocommerce'])->default('generic');
            $table->text('signing_secret')->nullable(); // encrypted cast
            $table->unsignedInteger('dedupe_window_seconds')->default(86400);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
