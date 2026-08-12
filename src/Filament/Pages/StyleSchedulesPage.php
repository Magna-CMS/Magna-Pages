<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Themes\StyleSchedule;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Themes\ThemeManager;

/**
 * Scheduled design changes: a token set that takes over the site's look
 * between two moments — a sale palette that starts Friday and ends
 * Monday without anyone awake to click Save.
 *
 * Only variables the active theme declares may be scheduled: the same
 * rule the Design tab follows, so a schedule can retune the site but
 * never invent variables a theme has no meaning for.
 */
class StyleSchedulesPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Scheduled design';

    protected static ?string $title = 'Scheduled design changes';

    protected static ?string $slug = 'pages-style-schedules';

    protected string $view = 'magna-pages::filament.style-schedules';

    public string $label = '';

    public string $startsAt = '';

    public string $endsAt = '';

    /** @var array<string, string> */
    public array $tokens = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.design') ?? false;
    }

    public function schedule(): void
    {
        $theme = app(ThemeManager::class)->active()?->name;
        if ($theme === null) {
            Notification::make()->title('Activate a theme first.')->warning()->send();

            return;
        }

        $label = trim($this->label);
        $startsAt = trim($this->startsAt);
        if ($label === '' || $startsAt === '') {
            Notification::make()->title('A schedule needs a label and a start time.')->warning()->send();

            return;
        }

        $declared = app(ThemeTokens::class)->themeVariables();
        $tokens = [];
        foreach ($this->tokens as $name => $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '' && array_key_exists($name, $declared)) {
                $tokens[$name] = $value;
            }
        }

        if ($tokens === []) {
            Notification::make()->title('Set at least one token to schedule.')->warning()->send();

            return;
        }

        StyleSchedule::query()->create([
            'theme' => $theme,
            'label' => $label,
            'tokens' => $tokens,
            'starts_at' => $startsAt,
            'ends_at' => trim($this->endsAt) !== '' ? trim($this->endsAt) : null,
        ]);

        // Cached pages were rendered against the old timeline.
        app(PageCache::class)->flush();

        $this->reset('label', 'startsAt', 'endsAt', 'tokens');
        Notification::make()->title('Design change scheduled.')->success()->send();
    }

    public function cancelSchedule(string $scheduleId): void
    {
        StyleSchedule::query()->whereKey($scheduleId)->delete();
        app(PageCache::class)->flush();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $theme = app(ThemeManager::class)->active()?->name;

        return [
            'themeName' => $theme,
            'declared' => app(ThemeTokens::class)->themeVariables(),
            'schedules' => $theme === null ? collect() : StyleSchedule::query()
                ->where('theme', $theme)
                ->orderBy('starts_at')
                ->get(),
            'active' => $theme === null ? null : StyleSchedule::activeFor($theme, now()),
        ];
    }
}
