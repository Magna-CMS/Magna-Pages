<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

/**
 * Reading a site-kit bundle row by row, the one way every theme-content
 * operation does it: the identity each kind of row is matched on (slug and
 * locale, handle, name), and the keys an admin sees for them
 * (page:about@, menu:primary, blog:posts/first-post).
 *
 * Shared by the theme update (ThemeKitUpdater) and the demo removal
 * (ThemeKitRemover) so the two can never disagree about which site row a
 * kit row means.
 */
trait ReadsKitRows
{
    /** @param array<string, mixed> $kit */
    private static function assertKit(array $kit): void
    {
        if (($kit['format'] ?? null) !== 'magna-site-kit' || ($kit['version'] ?? null) !== SiteKit::VERSION) {
            throw new \InvalidArgumentException('Not a magna-site-kit v'.SiteKit::VERSION.' bundle.');
        }
    }

    /**
     * @param  array<string, mixed>  $kit
     * @return list<array<string, mixed>>
     */
    private static function rows(array $kit, string $key): array
    {
        return is_array($kit[$key] ?? null) ? array_values(array_filter($kit[$key], 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?string  $identity
     * @return array<string, array<string, mixed>>
     */
    private static function byIdentity(array $rows, callable $identity): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($id = $identity($row)) !== null) {
                $keyed[$id] = $row;
            }
        }

        return $keyed;
    }

    /** @param array<string, mixed> $row */
    private static function entryIdentity(array $row): ?string
    {
        $slug = $row['slug'] ?? null;

        return is_string($slug) && $slug !== ''
            ? $slug.'@'.(is_string($row['locale'] ?? null) ? $row['locale'] : '')
            : null;
    }

    private static function entryKey(string $type, string $id): string
    {
        return ($type === 'page' ? 'page:' : 'template:').$id;
    }

    /** @param array<string, mixed> $row */
    private static function handleOf(array $row): ?string
    {
        return is_string($row['handle'] ?? null) && $row['handle'] !== '' ? $row['handle'] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nameOf(array $row): ?string
    {
        return is_string($row['name'] ?? null) && trim($row['name']) !== '' ? trim($row['name']) : null;
    }

    /** @param array<string, mixed> $row */
    private static function rowIdentity(array $row): ?string
    {
        foreach (['handle', 'slug', 'name', 'key'] as $field) {
            if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                return $row[$field];
            }
        }

        return null;
    }

    /**
     * A section row reduced to the fields the kit row carries — a section's
     * export may hold more than a theme ships, and a field the theme never
     * set cannot be an edit to its content.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private static function rowShape(array $row, array $shape): array
    {
        return array_intersect_key($row, $shape);
    }
}
