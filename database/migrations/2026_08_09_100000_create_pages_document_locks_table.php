<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per locked document (docs/magna-pages/03-BUILDER.md §2).
 *
 * A lock is holder + heartbeat, nothing more. Expiry is computed from
 * heartbeat_at at read time rather than stored, so a crashed browser that
 * never says goodbye releases its lock by simply going quiet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_document_locks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // One lock per document — the unique index IS the mutex; a
            // second acquire attempts an insert and loses the race in the
            // database, not in a check-then-act window.
            $table->char('entry_id', 26)->unique();
            $table->char('user_id', 26)->index();
            $table->timestamp('acquired_at');
            $table->timestamp('heartbeat_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_document_locks');
    }
};
