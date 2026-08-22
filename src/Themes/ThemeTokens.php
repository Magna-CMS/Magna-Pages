<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\Support\Carbon;
use Magna\Pages\PagesSettings;
use Magna\Themes\ThemeManager;

/**
 * Loads the active theme's tokens.json and compiles it to CSS custom
 * properties for the layout shell (docs/theme-development-guide.md §4).
 *
 * Naming rule (deterministic, documented for theme authors):
 *   colors.primary        → --color-primary
 *   layout.maxWidth       → --max-width
 *   typography.baseSize   → --base-size
 * i.e. color tokens get a `color-` prefix; every other category uses the
 * kebab-cased key alone. Values pass the same injection filter as section
 * tokenOverrides (no ';', no '(' — blocks url()/expression smuggling into
 * the style element).
 */
class ThemeTokens
{
    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * The effective tokens: the theme's declarations with the site's saved
     * overrides (Design tab) applied on top. Overrides only ever REPLACE a
     * declared variable — StyleManager refuses unknown names at write, and
     * the merge here ignores them too, so a stale row from a previous theme
     * version cannot introduce variables the theme never had.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $variables = $this->themeVariables();
        if ($variables === []) {
            return [];
        }

        foreach ($this->siteOverrides() as $name => $value) {
            if (array_key_exists($name, $variables)
                && is_string($value) && $value !== ''
                && ! str_contains($value, ';') && ! str_contains($value, '(')
            ) {
                $variables[$name] = $value;
            }
        }

        // A scheduled design change wins over the standing overrides for
        // as long as it runs — same declared-variables-only rule, so a
        // schedule can retune the site but never invent variables.
        foreach ($this->scheduledOverrides() as $name => $value) {
            if (array_key_exists($name, $variables)
                && $value !== '' && ! str_contains($value, ';') && ! str_contains($value, '(')
            ) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }

    /**
     * What the active theme itself declares, before site overrides — the
     * Design tab lists these and validates override names against them.
     * Active addons contribute ADDITIVELY (§5: "additive tokens only"): an
     * addon key the theme already declares is ignored, so an addon can add
     * a chat-bubble color but never repaint the theme.
     *
     * @return array<string, string>
     */
    public function themeVariables(): array
    {
        $active = $this->themes->active();
        if ($active === null) {
            return [];
        }

        $variables = $this->tokensFrom($active->name);
        if ($variables === []) {
            return [];
        }

        foreach ($this->themes->activeAddons() as $addon) {
            foreach ($this->tokensFrom($addon->name) as $name => $value) {
                if (! array_key_exists($name, $variables)) {
                    $variables[$name] = $value;
                }
            }
        }

        return $variables;
    }

    /**
     * The theme's DARK values: only the tokens that declare one.
     *
     * A token carries `dark` beside `value`, so a palette is one file with
     * two readings rather than two palettes to keep in step. A token with
     * no `dark` is simply the same in both schemes, which is the right
     * default for a corner radius or a content width.
     *
     * Site overrides are deliberately not applied here. An override
     * replaces what the theme declares for the LIGHT reading; making one
     * value mean both would leave a site unable to express a palette that
     * differs between them, which is the whole point of the feature.
     *
     * @return array<string, string>
     */
    public function darkVariables(): array
    {
        $active = $this->themes->active();
        if ($active === null) {
            return [];
        }

        $variables = $this->tokensFrom($active->name, dark: true);

        foreach ($this->themes->activeAddons() as $addon) {
            foreach ($this->tokensFrom($addon->name, dark: true) as $name => $value) {
                if (! array_key_exists($name, $variables)) {
                    $variables[$name] = $value;
                }
            }
        }

        return $variables;
    }

    /**
     * One package's tokens.json compiled to CSS variables (empty when the
     * file is missing or malformed).
     *
     * With $dark, only tokens that declare a `dark` value are returned, and
     * that value is what they are worth.
     *
     * @return array<string, string>
     */
    private function tokensFrom(string $packageName, bool $dark = false): array
    {
        $tokensFile = $this->themes->pathFor($packageName).'/tokens.json';
        if (! is_file($tokensFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($tokensFile), true);
        if (! is_array($decoded)) {
            return [];
        }

        $variables = [];
        foreach ($decoded as $category => $tokens) {
            if (! is_string($category) || ! is_array($tokens)) {
                continue;
            }

            foreach ($tokens as $key => $definition) {
                if (! is_string($key) || $key === '') {
                    continue;
                }

                if ($dark) {
                    // Only tokens that declare a dark reading take part.
                    $value = is_array($definition) ? ($definition['dark'] ?? null) : null;
                    if ($value === null) {
                        continue;
                    }
                } else {
                    $value = is_array($definition) ? ($definition['value'] ?? null) : $definition;
                }

                if (! is_string($value) && ! is_numeric($value)) {
                    continue;
                }

                $value = (string) $value;
                if ($value === '' || str_contains($value, ';') || str_contains($value, '(')) {
                    continue;
                }

                $name = $category === 'colors'
                    ? 'color-'.$this->kebab($key)
                    : $this->kebab($key);

                $variables['--'.$name] = $value;
            }
        }

        return $variables;
    }

    /**
     * The variables rendered as a `:root { … }` rule (empty string when no
     * theme or no tokens).
     */
    public function rootCss(): string
    {
        $variables = $this->cssVariables();
        if ($variables === []) {
            return '';
        }

        $scheme = PagesSettings::get()->color_scheme;
        $dark = $this->darkVariables();

        /*
         * A site pinned to one scheme has one palette, and that is the end
         * of it: no media query, no attribute, nothing for a visitor to
         * flip. Dark values simply replace the light ones at the root.
         */
        if ($scheme === 'dark' && $dark !== []) {
            return ':root{'.self::declare(array_merge($variables, $dark)).'}';
        }

        $css = ':root{'.self::declare($variables).'}';

        if ($scheme === 'light' || $dark === []) {
            return $css;
        }

        /*
         * Following the visitor's system preference, in THREE states and
         * one body.
         *
         * Every breakpoint of a responsive value already emits into one
         * body so the shared page cache still applies; a scheme is the same
         * kind of axis and gets the same treatment. Choosing server-side
         * would make the page per-visitor and cost the cache entirely.
         *
         * The media query is guarded against an explicit `light` so a
         * visitor who has chosen light is not overruled by their system,
         * and the attribute rule repeats the dark values so an explicit
         * `dark` wins in the other direction. Nothing here needs the theme
         * to cooperate: a theme that never stamps `data-theme` still gets
         * the system-preference reading, which is the common case.
         */
        $declarations = self::declare($dark);

        return $css
            .'@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){'.$declarations.'}}'
            .':root[data-theme="dark"]{'.$declarations.'}';
    }

    /** @param array<string, string> $variables */
    private static function declare(array $variables): string
    {
        $lines = [];
        foreach ($variables as $name => $value) {
            $lines[] = $name.':'.$value;
        }

        return implode(';', $lines);
    }

    /**
     * When the design next changes by schedule, if ever. The page cache
     * shortens its TTL to this so a scheduled palette cannot be served
     * late from a copy cached before the boundary.
     */
    public function nextScheduledChange(): ?Carbon
    {
        $theme = $this->themes->active()?->name;
        if ($theme === null) {
            return null;
        }

        try {
            return StyleSchedule::nextBoundary($theme, now());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The token set of whichever schedule is running right now.
     *
     * @return array<string, string>
     */
    private function scheduledOverrides(): array
    {
        $theme = $this->themes->active()?->name;
        if ($theme === null) {
            return [];
        }

        try {
            $schedule = StyleSchedule::activeFor($theme, now());
        } catch (\Throwable) {
            // Table not migrated yet: the site simply renders unscheduled.
            return [];
        }

        $tokens = $schedule?->tokens ?? [];

        return array_filter($tokens, 'is_string');
    }

    /** @return array<mixed, mixed> */
    private function siteOverrides(): array
    {
        $theme = $this->themes->active()?->name;
        if ($theme === null) {
            return [];
        }

        // Read directly rather than through StyleManager: the manager
        // depends on this class for validation, and a cycle for one query
        // buys nothing.
        try {
            $row = GlobalStyles::query()->where('theme', $theme)->first();
        } catch (\Throwable) {
            // Table not migrated yet (fresh install mid-upgrade) — the theme
            // simply renders unoverridden.
            return [];
        }

        return $row?->tokens ?? [];
    }

    private function kebab(string $key): string
    {
        $kebab = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $key);

        return strtolower(str_replace('_', '-', $kebab));
    }
}
