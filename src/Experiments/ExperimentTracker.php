<?php

declare(strict_types=1);

namespace Magna\Pages\Experiments;

use Illuminate\Support\Facades\DB;

/**
 * Counting for A/B sections. Both halves are atomic increments on a
 * unique (experiment, variant) row — a counter is the whole record, so a
 * flood costs one UPDATE and stores nothing about anybody.
 *
 * Names are validated here rather than trusted from the request: the
 * tracking endpoint is public, and an unbounded string would let anyone
 * seed rows into an admin screen.
 */
class ExperimentTracker
{
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/i';

    public function record(string $experiment, string $variant, string $event): bool
    {
        if (preg_match(self::NAME_PATTERN, $experiment) !== 1
            || preg_match(self::NAME_PATTERN, $variant) !== 1
            || ! in_array($event, ['exposure', 'conversion'], true)
        ) {
            return false;
        }

        // Only counts for variants a document actually declares — an
        // experiment nobody built cannot be created from outside.
        if (! ExperimentStat::query()->where('experiment', $experiment)->where('variant', $variant)->exists()) {
            return false;
        }

        $column = $event === 'exposure' ? 'exposures' : 'conversions';

        DB::table('pages_experiment_stats')
            ->where('experiment', $experiment)
            ->where('variant', $variant)
            ->increment($column);

        return true;
    }

    /**
     * Ensure a row exists for every variant a rendered document declares.
     * Called at render, so the admin screen lists an experiment as soon as
     * it goes live rather than after its first visitor.
     *
     * @param  array<string, list<string>>  $variantsByExperiment
     */
    public function ensure(array $variantsByExperiment): void
    {
        foreach ($variantsByExperiment as $experiment => $variants) {
            foreach ($variants as $variant) {
                ExperimentStat::query()->firstOrCreate(
                    ['experiment' => $experiment, 'variant' => $variant],
                );
            }
        }
    }
}
