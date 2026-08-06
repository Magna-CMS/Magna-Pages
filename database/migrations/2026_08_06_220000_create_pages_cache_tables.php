<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HTML page cache (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §B1):
 * rendered responses stored URL → body with their surrogate-key set in an
 * inverted index, so a cache hit is one indexed read and publishing purges
 * exactly the affected URLs by key → URL lookup — never "clear all cache"
 * as the primary tool.
 *
 * DB-backed on purpose: the target market is shared PHP hosting where the
 * database is the one dependable store; Redis/edge layers stack on top
 * later without changing the key model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_cache', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Hash-keyed lookup: URLs exceed index limits and the hot path
            // is an exact-match read.
            $table->char('url_hash', 64)->unique();
            $table->string('url', 2048);
            $table->mediumText('body');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('pages_cache_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cache_id')->constrained('pages_cache')->onDelete('cascade');
            $table->string('surrogate_key');
            $table->index('surrogate_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_cache_keys');
        Schema::dropIfExists('pages_cache');
    }
};
