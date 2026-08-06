<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menus: named nested trees assigned to theme locations
 * (docs/magna-pages/05 §2).
 *
 * - pages_menu_items.page_id references the generated magna_entries_page
 *   table and is deliberately FK-less (dynamic tables cannot carry real
 *   foreign keys — same documented boundary as magna_relations); dangling
 *   references are tolerated at render and pruned by the deletion protocol.
 * - pages_menu_revisions is the append-only history behind the "menus are
 *   versioned" guarantee (10-REVIEW-RESOLUTIONS §A3): every structural save
 *   snapshots the full tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_menus', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('handle')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pages_menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_id')->constrained('pages_menus')->onDelete('cascade');
            $table->char('parent_id', 26)->nullable()->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('label');
            $table->string('type', 20)->default('url'); // url | page
            $table->char('page_id', 26)->nullable();    // FK-less, see header
            $table->string('url', 2048)->nullable();
            $table->string('target', 20)->nullable();   // e.g. _blank
            $table->json('settings')->nullable();       // visibility rules, badges (later phases)
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'position']);
        });

        Schema::create('pages_menu_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_id')->constrained('pages_menus')->onDelete('cascade');
            $table->json('payload');
            $table->char('author_id', 26)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_menu_revisions');
        Schema::dropIfExists('pages_menu_items');
        Schema::dropIfExists('pages_menus');
    }
};
