<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled design changes: a token set that becomes the site's look at
 * a stated moment (a sale palette on Friday, back to normal on Monday)
 * without anyone being awake to click Save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_style_schedules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('theme');
            $table->string('label');
            $table->json('tokens');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['theme', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_style_schedules');
    }
};
