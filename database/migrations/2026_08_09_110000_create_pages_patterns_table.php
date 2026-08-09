<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My Library: reusable document subtrees (docs/magna-pages/03-BUILDER.md §6).
 *
 * A pattern is a COPY at save time and a COPY at insert time — never a
 * reference. Editing a pattern later must not rewrite pages it was already
 * placed on; that live-reference behavior is what template parts are for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_patterns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            // What the stored subtree is: a whole section, or a single block.
            $table->string('kind', 10)->index();
            $table->json('document');
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_patterns');
    }
};
