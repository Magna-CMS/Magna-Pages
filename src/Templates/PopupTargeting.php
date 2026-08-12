<?php

declare(strict_types=1);

namespace Magna\Pages\Templates;

/**
 * Popup targeting rules, read off a popup document's root settings
 * (`settings.popup`): where it may appear, and how insistently.
 *
 * Path targeting is decided SERVER-side because the path is already part
 * of the cache key — a popup that only shows on /pricing costs the cache
 * nothing. Frequency, delay and scroll are per-visitor facts, so they
 * ride out as data attributes and the client decides; baking those into
 * HTML is what would break the shared cache.
 */
final class PopupTargeting
{
    private const FREQUENCIES = ['once', 'session', 'always'];

    /**
     * @param  array<mixed, mixed>  $document
     * @return array{frequency: string, delay: int, scroll: int}
     */
    public static function behaviour(array $document): array
    {
        $rules = self::rules($document);

        $frequency = $rules['frequency'] ?? null;

        return [
            'frequency' => is_string($frequency) && in_array($frequency, self::FREQUENCIES, true)
                ? $frequency
                : 'once',
            // Clamped: a popup that waits five minutes is a bug, and a
            // negative delay is an attempt at something.
            'delay' => max(0, min(300, is_numeric($rules['delay'] ?? null) ? (int) $rules['delay'] : 0)),
            'scroll' => max(0, min(100, is_numeric($rules['scroll'] ?? null) ? (int) $rules['scroll'] : 0)),
        ];
    }

    /**
     * Whether this popup targets the given site path. Include rules win
     * by being explicit: with any `paths` set, ONLY those match; `exclude`
     * then removes from whatever remains.
     *
     * @param  array<mixed, mixed>  $document
     */
    public static function matchesPath(array $document, string $path): bool
    {
        $rules = self::rules($document);
        $path = '/'.trim($path, '/');

        $include = self::patterns($rules['paths'] ?? null);
        if ($include !== [] && ! self::anyMatches($include, $path)) {
            return false;
        }

        return ! self::anyMatches(self::patterns($rules['exclude'] ?? null), $path);
    }

    /**
     * @param  array<mixed, mixed>  $document
     * @return array<mixed, mixed>
     */
    private static function rules(array $document): array
    {
        // The rules live on the document's first section settings — a
        // popup document's root, the same place its conditions live.
        $sections = array_is_list($document) ? $document : ($document['sections'] ?? []);
        $first = is_array($sections) ? ($sections[0] ?? null) : null;
        $settings = is_array($first) ? ($first['settings'] ?? null) : null;
        $popup = is_array($settings) ? ($settings['popup'] ?? null) : null;

        return is_array($popup) ? $popup : [];
    }

    /**
     * @return list<string>
     */
    private static function patterns(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $p): string => is_string($p) ? trim($p) : '', $value),
            fn (string $p): bool => $p !== '',
        ));
    }

    /** @param list<string> $patterns */
    private static function anyMatches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            // Shell-style globs only: `/blog/*` is a rule an editor can
            // write and reason about; a regex from settings is a footgun.
            if (fnmatch('/'.trim($pattern, '/'), $path) === true) {
                return true;
            }
        }

        return false;
    }
}
