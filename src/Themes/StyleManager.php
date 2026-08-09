<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Themes\ThemeManager;

/**
 * Site token overrides: what the Design tab writes (03-BUILDER §4).
 *
 * Overrides are keyed by CSS variable name and only accepted for variables
 * the ACTIVE THEME actually declares — the Design tab is for retuning the
 * theme's palette, not for smuggling arbitrary custom properties into every
 * page. Values pass the same injection filter as every other token path.
 *
 * Every save appends a revision before replacing the current row, so a bad
 * palette change is one revert away rather than gone.
 */
final class StyleManager
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ThemeTokens $tokens,
    ) {}

    /**
     * Current overrides for the active theme.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $theme = $this->themes->active()?->name;
        if ($theme === null) {
            return [];
        }

        $row = GlobalStyles::query()->where('theme', $theme)->first();

        /** @var array<string, string> */
        return $row?->tokens ?? [];
    }

    /**
     * Replace the overrides for the active theme.
     *
     * @param  array<mixed, mixed>  $tokens  variable name => value
     * @return array<string, string> The cleaned set as stored
     *
     * @throws PatchException
     */
    public function put(array $tokens, ?string $userId): array
    {
        $theme = $this->themes->active()?->name;
        if ($theme === null) {
            throw new PatchException('No active theme to override.');
        }

        $declared = $this->tokens->themeVariables();
        $clean = [];

        foreach ($tokens as $name => $value) {
            if (! is_string($name) || ! array_key_exists($name, $declared)) {
                throw new PatchException("The active theme declares no \"{$name}\" token.");
            }
            if (! is_string($value) && ! is_numeric($value)) {
                throw new PatchException("The value for \"{$name}\" must be a string.");
            }

            $value = trim((string) $value);
            if ($value === '' || str_contains($value, ';') || str_contains($value, '(')) {
                throw new PatchException("The value for \"{$name}\" is not a plain token value.");
            }
            if (mb_strlen($value) > 120) {
                throw new PatchException("The value for \"{$name}\" is too long.");
            }

            $clean[$name] = $value;
        }

        DB::transaction(function () use ($theme, $clean, $userId): void {
            DB::table('pages_style_revisions')->insert([
                'id' => strtolower((string) Str::ulid()),
                'theme' => $theme,
                'tokens' => (string) json_encode($clean),
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            GlobalStyles::query()->updateOrCreate(
                ['theme' => $theme],
                ['tokens' => $clean, 'updated_by' => $userId],
            );
        });

        return $clean;
    }
}
