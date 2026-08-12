<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B results as COUNTERS, not per-visitor rows: an experiment needs
 * exposures and conversions per variant, and nothing about who. Storing
 * less is both the privacy answer and the volume answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_experiment_stats', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('experiment');
            $table->string('variant');
            $table->unsignedBigInteger('exposures')->default(0);
            $table->unsignedBigInteger('conversions')->default(0);
            $table->timestamps();

            $table->unique(['experiment', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_experiment_stats');
    }
};
