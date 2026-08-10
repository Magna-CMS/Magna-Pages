<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflow v1 (docs/magna-pages/08-BUILD-PHASES.md Phase B item 10):
 * request-publish → pending → approve or return.
 *
 * Requests are rows, not entry state: an entry's status stays the content
 * engine's business (draft/published), and a request is a conversation
 * ABOUT that entry. Resolved requests are kept — who asked, who shipped,
 * who returned and why is exactly the history an editorial team goes
 * looking for later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_publish_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entry_id', 26)->index();
            $table->char('requested_by', 26);
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending')->index();
            $table->char('resolved_by', 26)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['entry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_publish_requests');
    }
};
