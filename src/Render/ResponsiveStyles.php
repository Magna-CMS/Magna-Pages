<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * Per-device style values, and the CSS they become.
 *
 * A style value may be a scalar — the same at every width — or the
 * sentinel `{"$responsive": {"base": …, "tablet": …, "mobile": …}}`,
 * mirroring the `$bind` convention the document already uses. Both are
 * legal wherever a scalar was legal, so a document written before this
 * existed means exactly what it meant before.
 *
 * TWO PROPERTIES MAKE THIS SAFE, and both are asserted by tests:
 *
 * 1. **The page cache is untouched.** The renderer cannot pick a
 *    breakpoint — one body is served to every visitor — so it emits all
 *    of them and lets the browser choose. The bytes are identical for
 *    everyone, which is exactly what the shared cache requires.
 *
 * 2. **A document with no responsive value renders byte-identically.**
 *    The node class and the stylesheet appear only when a node actually
 *    declares one. Nothing is added to a page that does not use this.
 *
 * Breakpoints match the editor's three devices and the visibility classes
 * already in the partial: mobile below 768, tablet 768–1023, desktop
 * 1024 and up. Overrides are emitted as max-width rules so they cascade —
 * a mobile viewport matches the tablet rule too, which is why a value
 * declared for tablet and not for mobile keeps applying at mobile width.
 */
final class ResponsiveStyles
{
    /** The narrower-than-desktop breakpoints, widest first so they cascade. */
    public const BREAKPOINTS = [
        'tablet' => '(max-width: 1023.98px)',
        'mobile' => '(max-width: 767.98px)',
    ];

    /** The class a node carries only when it has per-device values. */
    public static function nodeClass(string $nodeId): string
    {
        return 'magna-n-'.preg_replace('/[^a-z0-9-]/i', '', $nodeId);
    }

    /**
     * Whether this style set says anything about a narrower screen.
     *
     * Checked before a class or a rule is emitted, so a page that does
     * not use the feature carries no trace of it.
     */
    public static function isResponsive(mixed $style): bool
    {
        if (! is_array($style)) {
            return false;
        }

        foreach ($style as $value) {
            if (is_array($value) && isset($value['$responsive']) && is_array($value['$responsive'])) {
                foreach (array_keys(self::BREAKPOINTS) as $breakpoint) {
                    if (array_key_exists($breakpoint, $value['$responsive'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * One value at one breakpoint, or null when this breakpoint says
     * nothing about it. `base` reads a plain scalar unchanged, which is
     * what makes the sentinel optional rather than a migration.
     */
    public static function valueAt(mixed $value, string $breakpoint): mixed
    {
        if (! is_array($value) || ! isset($value['$responsive'])) {
            return $breakpoint === 'base' ? $value : null;
        }

        $per = $value['$responsive'];

        return is_array($per) && array_key_exists($breakpoint, $per) ? $per[$breakpoint] : null;
    }

    /**
     * The media-query rules a node needs, or an empty string when it
     * needs none.
     */
    public static function rulesFor(
        string $nodeId,
        mixed $style,
        string $kind,
        bool $withBase = false,
        string $within = '',
    ): string {
        if (! $withBase && ! self::isResponsive($style)) {
            return '';
        }

        // `$within` targets an element INSIDE the node — the row a section's
        // columns lay out in, which the section's own style attribute
        // cannot reach.
        $selector = '.'.self::nodeClass($nodeId).($within === '' ? '' : ' '.$within);
        $css = '';

        // A block has no attribute of ours to carry its base declarations,
        // so they ride the stylesheet as well — without !important, since
        // nothing of the block's own is being overridden.
        if ($withBase) {
            $base = StyleDescriptors::declarations($style, $kind);
            if ($base !== '') {
                $css .= $selector.'{'.$base.'}';
            }
        }

        foreach (self::BREAKPOINTS as $breakpoint => $query) {
            $declarations = StyleDescriptors::declarations($style, $kind, $breakpoint);
            if ($declarations === '') {
                continue;
            }

            // The node's own attribute already carries the base values at
            // equal specificity, and a later rule wins a tie — but the
            // style ATTRIBUTE always beats a stylesheet rule, so the
            // override has to say so.
            $css .= '@media '.$query.'{'.$selector.'{'
                .str_replace(';', ' !important;', $declarations).' !important}}';
        }

        return $css;
    }
}
