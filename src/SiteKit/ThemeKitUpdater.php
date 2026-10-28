<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Magna\Content\Entry;
use Magna\Content\EntryRevisionRecorder;
use Magna\Content\Models\Revision;
use Magna\Themes\ThemeContentUpdater;

/**
 * The page builder's half of a theme update: carry the theme's new pages,
 * template parts, menus, patterns and plugin records onto the site without
 * overwriting what an editor changed (core's ThemeContentUpdater).
 *
 * Every entry is compared in three versions, all in KitComparer's comparison
 * form so that the database's own normalisation (jsonb key order, at-rest
 * rich-text sanitising) never reads as an edit:
 *
 *   site has none                 → added
 *   site = incoming               → nothing to do
 *   site = outgoing (base)        → updated to the incoming version
 *   anything else                 → kept, reported for review
 *
 * The entries to change are then applied as one partial kit through
 * SiteKit::apply(), so they land in one transaction and a page keeps its
 * revision trail exactly as a sync would leave it. Settings follow the same
 * rule for the home and not-found pages; maintenance mode is never touched.
 *
 * Plugin sections (forms, posts) are matched row by row on the first of
 * handle, slug, name or key a row carries — the identities the shipped
 * sections use. A section row that has none is only ever added, never
 * changed. Patterns and plugin records keep no history of their own, so
 * replace() can restore pages and menus from history but not those.
 */
final class ThemeKitUpdater implements ThemeContentUpdater
{
    private const ENTRY_KINDS = ['page' => 'pages', 'pages_template' => 'templates'];

    public function __construct(
        private readonly SiteKit $kits,
        private readonly KitComparer $compare,
        private readonly EntryRevisionRecorder $revisions,
    ) {}

    public function update(array $base, array $kit, ?string $actorId = null): array
    {
        self::assertKit($kit);

        $report = ['updated' => [], 'added' => [], 'kept' => []];
        $partial = self::emptyKit();

        foreach (self::ENTRY_KINDS as $type => $key) {
            $baseRows = self::byIdentity(self::rows($base, $key), self::entryIdentity(...));

            foreach (self::rows($kit, $key) as $row) {
                $id = self::entryIdentity($row);
                if ($id === null) {
                    continue;
                }
                [$slug, $locale] = explode('@', $id, 2);
                $baseRow = $baseRows[$id] ?? null;

                // Compared field by field against each kit row in turn: the
                // site is "as shipped" when it matches the fields that row
                // sets (see KitComparer).
                $verdict = $this->verdict(
                    $this->compare->localEntryState($type, $slug, $locale, $row),
                    KitComparer::entryStateOf($row),
                    $baseRow === null ? null : $this->compare->localEntryState($type, $slug, $locale, $baseRow),
                    $baseRow === null ? null : KitComparer::entryStateOf($baseRow),
                );

                $this->record($verdict, self::entryKey($type, $id), $report, $partial[$key], $row);
            }
        }

        $baseMenus = self::byIdentity(self::rows($base, 'menus'), self::handleOf(...));
        foreach (self::rows($kit, 'menus') as $row) {
            $handle = self::handleOf($row);
            if ($handle === null || ! is_array($row['tree'] ?? null)) {
                continue;
            }

            $localMenu = $this->compare->localMenuState($handle);
            $verdict = $this->verdict(
                $localMenu,
                KitComparer::menuStateOf($row['tree']),
                $localMenu,
                is_array($baseMenus[$handle]['tree'] ?? null) ? KitComparer::menuStateOf($baseMenus[$handle]['tree']) : null,
            );

            $this->record($verdict, 'menu:'.$handle, $report, $partial['menus'], $row);
        }

        $basePatterns = self::byIdentity(self::rows($base, 'patterns'), self::nameOf(...));
        foreach (self::rows($kit, 'patterns') as $row) {
            $name = self::nameOf($row);
            if ($name === null) {
                continue;
            }

            $localPattern = $this->compare->localPatternState($name);
            $verdict = $this->verdict(
                $localPattern,
                KitComparer::patternStateOf($row),
                $localPattern,
                isset($basePatterns[$name]) ? KitComparer::patternStateOf($basePatterns[$name]) : null,
            );

            $this->record($verdict, 'pattern:'.$name, $report, $partial['patterns'], $row);
        }

        foreach ($this->kits->sections() as $sectionKey => $section) {
            if (! is_array($kit[$sectionKey] ?? null)) {
                continue;
            }

            $local = self::byIdentity($section->export(), self::rowIdentity(...));
            $baseRows = self::byIdentity(self::rows($base, $sectionKey), self::rowIdentity(...));
            $partial[$sectionKey] = [];

            foreach (self::rows($kit, $sectionKey) as $row) {
                $id = self::rowIdentity($row);

                // No identity to match on: only a row the section itself
                // calls new may go in — the import rule, nothing stronger.
                if ($id === null) {
                    if (($section->diff([$row])['added'] ?? []) !== []) {
                        $this->record('add', $sectionKey.'/(unnamed)', $report, $partial[$sectionKey], $row);
                    }

                    continue;
                }

                $baseRow = $baseRows[$id] ?? null;
                $verdict = $this->verdict(
                    isset($local[$id]) ? KitComparer::canonical(self::rowShape($local[$id], $row)) : null,
                    KitComparer::canonical($row),
                    isset($local[$id], $baseRow) ? KitComparer::canonical(self::rowShape($local[$id], $baseRow)) : null,
                    $baseRow === null ? null : KitComparer::canonical($baseRow),
                );

                $this->record($verdict, $sectionKey.'/'.$id, $report, $partial[$sectionKey], $row);
            }
        }

        $partial['settings'] = $this->settingsToFollow($base, $kit);

        if (self::hasWork($partial)) {
            $this->kits->apply($partial, $actorId);
        }

        return $report;
    }

    public function replace(array $kit, array $keys, ?string $actorId = null): int
    {
        self::assertKit($kit);

        $wanted = array_flip($keys);
        $partial = self::emptyKit();
        $replaced = 0;

        foreach (self::ENTRY_KINDS as $type => $key) {
            foreach (self::rows($kit, $key) as $row) {
                $id = self::entryIdentity($row);
                if ($id === null || ! isset($wanted[self::entryKey($type, $id)])) {
                    continue;
                }

                $this->keepRestorable($type, $id, $actorId);
                $partial[$key][] = $row;
                $replaced++;
            }
        }

        foreach (['menus' => [self::handleOf(...), 'menu:'], 'patterns' => [self::nameOf(...), 'pattern:']] as $key => [$identity, $prefix]) {
            foreach (self::rows($kit, $key) as $row) {
                $id = $identity($row);
                if ($id !== null && isset($wanted[$prefix.$id])) {
                    $partial[$key][] = $row;
                    $replaced++;
                }
            }
        }

        foreach (array_keys($this->kits->sections()) as $sectionKey) {
            foreach (self::rows($kit, $sectionKey) as $row) {
                $id = self::rowIdentity($row);
                if ($id !== null && isset($wanted[$sectionKey.'/'.$id])) {
                    $partial[$sectionKey][] = $row;
                    $replaced++;
                }
            }
        }

        if ($replaced > 0) {
            $this->kits->apply($partial, $actorId);
        }

        return $replaced;
    }

    /**
     * "add", "update", "same" or "keep" for one entry. The site's state is
     * given twice — as it compares with the incoming row and with the base
     * row — because entries are compared only on the fields each row sets.
     */
    private function verdict(?string $siteVsIncoming, string $incoming, ?string $siteVsBase, ?string $base): string
    {
        return match (true) {
            $siteVsIncoming === null => 'add',
            $siteVsIncoming === $incoming => 'same',
            $base !== null && $siteVsBase === $base => 'update',
            default => 'keep',
        };
    }

    /**
     * @param  array{updated: list<string>, added: list<string>, kept: list<string>}  $report
     * @param  list<array<string, mixed>>  $bucket
     * @param  array<string, mixed>  $row
     */
    private function record(string $verdict, string $key, array &$report, array &$bucket, array $row): void
    {
        match ($verdict) {
            'add' => $report['added'][] = $key,
            'update' => $report['updated'][] = $key,
            'keep' => $report['kept'][] = $key,
            default => null,
        };

        if ($verdict === 'add' || $verdict === 'update') {
            $bucket[] = $row;
        }
    }

    /**
     * The home and not-found pages move only when the site still points
     * where the outgoing theme left them.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $kit
     * @return array<string, string>
     */
    private function settingsToFollow(array $base, array $kit): array
    {
        $baseSettings = is_array($base['settings'] ?? null) ? $base['settings'] : [];
        $kitSettings = is_array($kit['settings'] ?? null) ? $kit['settings'] : [];
        $follow = [];

        foreach (['home_page_slug', 'not_found_page_slug'] as $setting) {
            $wanted = $kitSettings[$setting] ?? null;
            if (! is_string($wanted) || $wanted === '') {
                continue;
            }

            $current = $this->kits->localSettingSlug($setting);
            $before = $baseSettings[$setting] ?? null;

            if ($current !== $wanted && ($current === null || $current === $before)) {
                $follow[$setting] = $wanted;
            }
        }

        return $follow;
    }

    /**
     * A page about to be overwritten at an admin's request must stay
     * restorable. A published page gets a revision from the save itself; a
     * draft does not, so it gets a restore point first.
     */
    private function keepRestorable(string $type, string $id, ?string $actorId): void
    {
        [$slug, $locale] = explode('@', $id, 2);

        /** @var Entry|null $entry */
        $entry = Entry::type($type)->where('slug', $slug)->where('locale', $locale)->first();

        if ($entry !== null && ! $entry->isPublished()) {
            $this->revisions->record($entry, $type, $actorId, Revision::KIND_RESTORE_POINT, 'Before theme version');
        }
    }

    /** @param array<string, mixed> $kit */
    private static function assertKit(array $kit): void
    {
        if (($kit['format'] ?? null) !== 'magna-site-kit' || ($kit['version'] ?? null) !== SiteKit::VERSION) {
            throw new \InvalidArgumentException('Not a magna-site-kit v'.SiteKit::VERSION.' bundle.');
        }
    }

    /** @return array<string, mixed> */
    private static function emptyKit(): array
    {
        return ['format' => 'magna-site-kit', 'version' => SiteKit::VERSION, 'pages' => [], 'templates' => [], 'menus' => [], 'patterns' => []];
    }

    /** @param array<string, mixed> $partial */
    private static function hasWork(array $partial): bool
    {
        foreach ($partial as $key => $value) {
            if (! in_array($key, ['format', 'version'], true) && is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
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
