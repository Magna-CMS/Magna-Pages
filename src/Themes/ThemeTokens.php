<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

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
     * One package's tokens.json compiled to CSS variables (empty when the
     * file is missing or malformed).
     *
     * @return array<string, string>
     */
    private function tokensFrom(string $packageName): array
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

                $value = is_array($definition) ? ($definition['value'] ?? null) : $definition;
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

        $lines = [];
        foreach ($variables as $name => $value) {
            $lines[] = $name.':'.$value;
        }

        return ':root{'.implode(';', $lines).'}';
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
