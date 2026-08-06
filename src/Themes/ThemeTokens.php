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
     * The active theme's tokens as a CSS custom-property map.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $active = $this->themes->active();
        if ($active === null) {
            return [];
        }

        $tokensFile = $this->themes->pathFor($active->name).'/tokens.json';
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

    private function kebab(string $key): string
    {
        $kebab = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $key);

        return strtolower(str_replace('_', '-', $kebab));
    }
}
