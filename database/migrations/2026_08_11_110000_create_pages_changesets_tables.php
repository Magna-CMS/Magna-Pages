<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changesets (§A3): a named group of documents whose pending edits go
 * live together — one publish, all or nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_changesets', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('status')->default('open');
            $table->char('author_id', 26)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pages_changeset_items', function (Blueprint $table): void {
            $table->char('changeset_id', 26);
            $table->string('entry_type');
            $table->char('entry_id', 26);
            $table->primary(['changeset_id', 'entry_id']);
            $table->foreign('changeset_id')->references('id')->on('pages_changesets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_changeset_items');
        Schema::dropIfExists('pages_changesets');
    }
};
