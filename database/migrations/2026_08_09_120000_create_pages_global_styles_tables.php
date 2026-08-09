<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site token overrides (docs/magna-pages/03-BUILDER.md §4, §A8).
 *
 * One current row per site keyed by the theme it overrides — switching
 * themes must not carry one theme's palette onto another whose tokens mean
 * different things. History is its own append-only table rather than
 * versioned rows in place, the same shape as pages_menu_revisions: current
 * state stays a single cheap read, history grows without slowing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_global_styles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('theme', 120)->unique();
            $table->json('tokens');
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('pages_style_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('theme', 120)->index();
            $table->json('tokens');
            $table->char('created_by', 26)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_style_revisions');
        Schema::dropIfExists('pages_global_styles');
    }
};
