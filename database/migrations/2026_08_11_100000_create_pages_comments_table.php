<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Node-anchored comments (docs/magna-pages §F): editorial discussion on a
 * page or on one node of it. page_id/node_id reference dynamic-table rows
 * and document nodes, so no FKs — a comment whose anchor vanished still
 * reads as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_comments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('page_id', 26)->index();
            $table->string('node_id')->nullable();
            $table->char('author_id', 26)->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_comments');
    }
};
