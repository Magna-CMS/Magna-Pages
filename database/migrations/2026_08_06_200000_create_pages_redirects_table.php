<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redirect table for the public site: manual redirects plus the automatic
 * 301s written on page slug renames (docs/magna-pages/05 §3).
 *
 * No hit counter by design — a write per redirect hit on the read-only
 * public path is the exact anti-pattern the plan review flagged; usage
 * stats arrive later via a cache-buffered counter if ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_redirects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source_path', 2048);
            $table->string('target_path', 2048);
            $table->unsignedSmallInteger('status')->default(301);
            $table->string('locale', 10)->default('');
            $table->boolean('automatic')->default(false);
            $table->timestamps();

            // One redirect per source per locale; source lookup is the hot
            // path (every 404-bound request checks it once).
            $table->unique(['source_path', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_redirects');
    }
};
