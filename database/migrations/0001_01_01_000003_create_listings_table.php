<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->unique();
            $table->string('fingerprint', 32)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('PLN');
            $table->decimal('area_m2', 8, 2);
            $table->integer('rooms');
            $table->string('city');
            $table->string('street')->nullable();
            $table->string('type');
            $table->string('status')->default('available');
            $table->json('images')->nullable();
            $table->json('keywords')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // ── Single-column indexes ────────────────────────────────
            $table->index('price');
            $table->index('area_m2');
            $table->index('rooms');
            $table->index('city');
            $table->index('type');
            $table->index('status');
            $table->index('created_at');

            // ── Composite indexes ────────────────────────────────────
            $table->index(['fingerprint', 'updated_at'], 'listings_fingerprint_freshness_index');
            $table->index(['status', 'price'], 'listings_status_price_index');
            $table->index(['status', 'created_at'], 'listings_status_created_at_index');
            $table->index(['status', 'area_m2'], 'listings_status_area_m2_index');
        });

        // Full-text index for semantic keyword fallback
        if (config('database.default') === 'mysql') {
            DB::statement('ALTER TABLE listings ADD FULLTEXT INDEX listings_fulltext_index (title, description)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
