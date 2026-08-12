<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Pages\Page;
use Magna\Pages\Experiments\ExperimentStat;

/**
 * A/B results: exposures, conversions, and the rate per variant, grouped
 * by experiment. Read-only except for clearing a finished experiment —
 * the numbers are evidence, and evidence you can edit is not evidence.
 */
class ExperimentsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Experiments';

    protected static ?string $title = 'Experiments';

    protected static ?string $slug = 'pages-experiments';

    protected string $view = 'magna-pages::filament.experiments';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.publish') ?? false;
    }

    /** Wipe one experiment's counters — a fresh start, not an edit. */
    public function resetExperiment(string $experiment): void
    {
        ExperimentStat::query()->where('experiment', $experiment)
            ->update(['exposures' => 0, 'conversions' => 0]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $stats = ExperimentStat::query()
            ->orderBy('experiment')
            ->orderBy('variant')
            ->get();

        return ['experiments' => $stats->groupBy('experiment')];
    }
}
