<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $theme
 * @property string $label
 * @property array<string, string> $tokens
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
final class StyleSchedule extends Model
{
    use HasUlids;

    protected $table = 'pages_style_schedules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The schedule in force for a theme at a moment: the latest one that
     * has started and has not ended. Latest wins, so a correction posted
     * after a mistake takes over without deleting history.
     */
    public static function activeFor(string $theme, Carbon $at): ?self
    {
        return self::query()
            ->where('theme', $theme)
            ->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * When the effective design next changes for a theme — the page cache
     * shortens its TTL to this, so a scheduled palette can never be
     * served late from a cached copy.
     */
    public static function nextBoundary(string $theme, Carbon $at): ?Carbon
    {
        $starts = self::query()->where('theme', $theme)
            ->where('starts_at', '>', $at)->min('starts_at');
        $ends = self::query()->where('theme', $theme)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)->min('ends_at');

        $moments = array_values(array_filter(
            [is_string($starts) ? Carbon::parse($starts) : null, is_string($ends) ? Carbon::parse($ends) : null],
        ));
        if ($moments === []) {
            return null;
        }

        return count($moments) === 1 ? $moments[0] : min($moments);
    }
}
