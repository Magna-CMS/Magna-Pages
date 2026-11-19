<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\SchemaRegistry;
use Magna\Contracts\SiteKitSection;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Builder\PatternManager;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;

/**
 * The site-kit bundle (docs/magna-pages §F): everything the Pages plugin
 * considers "the site" — pages, template documents, menus, global styles,
 * pages settings — as one JSON document, for export, diff, and promotion
 * between environments (staging → production).
 *
 * Everything cross-references by SLUG, never by id: ids are environment
 * facts, slugs are the site's own names. Media is referenced by id and NOT
 * bundled (v1 limitation — promote media separately). Sync UPSERTS only:
 * rows present locally but absent from the kit are reported by diff and
 * left alone, because deletion deserves an explicit flag, not a default.
 *
 * Beyond its own keys the kit carries plugin-owned SECTIONS (SiteKitSection,
 * registered via the RegistersSiteKitSections contract, collected under the
 * 'magna.site_kit.sections' binding): a form the form block selects by
 * handle has to travel with the site, or the kit applies cleanly and the
 * page ships with a hole an admin must hand-fill. A section key no enabled
 * plugin claims is skipped and reported (see unknownKeys()), never fatal —
 * an older installation reads a newer kit and says what it could not place.
 */
class SiteKit
{
    public const VERSION = 1;

    /** The kit's own top-level keys; a plugin section may never claim one. */
    private const OWN_KEYS = ['format', 'version', 'pages', 'templates', 'menus', 'patterns', 'settings'];

    /**
     * @param  list<SiteKitSection>  $sections
     */
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly EntryManager $entries,
        private readonly MenuManager $menus,
        private readonly PatternManager $patterns,
        private readonly PageSlugs $slugs,
        private readonly array $sections = [],
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $settings = PagesSettings::get();

        $kit = [
            'format' => 'magna-site-kit',
            'version' => self::VERSION,
            'pages' => $this->exportEntries('page'),
            'templates' => $this->exportEntries('pages_template'),
            'menus' => $this->exportMenus(),
            'patterns' => $this->exportPatterns(),
            'settings' => [
                'home_page_slug' => $this->slugs->slugForId($settings->home_page_id),
                'not_found_page_slug' => $this->slugs->slugForId($settings->not_found_page_id),
                'maintenance_mode' => $settings->maintenance_mode,
                'collection_mounts' => $settings->collection_mounts,
            ],
        ];

        foreach ($this->sectionMap() as $key => $section) {
            $kit[$key] = $section->export();
        }

        return $kit;
    }

    /**
     * What applying the kit would change, without changing it.
     *
     * @param  array<string, mixed>  $kit
     * @return array<string, array<string, list<string>>>
     */
    public function diff(array $kit): array
    {
        $diff = [
            'pages' => $this->diffEntries('page', $this->kitEntries($kit, 'pages')),
            'templates' => $this->diffEntries('pages_template', $this->kitEntries($kit, 'templates')),
            'menus' => $this->diffMenus($this->kitMenus($kit)),
        ];

        foreach ($this->sectionMap() as $key => $section) {
            $diff[$key] = $section->diff($this->kitSectionRows($kit, $key));
        }

        return $diff;
    }

    /**
     * Refuse anything that is not a site kit this version reads.
     *
     * @param  array<string, mixed>  $kit
     *
     * @throws \InvalidArgumentException
     */
    public static function assertKit(array $kit): void
    {
        if (($kit['format'] ?? null) !== 'magna-site-kit' || ($kit['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Not a magna-site-kit v'.self::VERSION.' bundle.');
        }
    }

    /**
     * The kit's top-level keys that nothing here will read: not the kit's
     * own, and no enabled plugin registers a section for them. Reported by
     * sync and diff so a kit written on a newer or fuller installation
     * degrades loudly instead of silently.
     *
     * @param  array<string, mixed>  $kit
     * @return list<string>
     */
    public function unknownKeys(array $kit): array
    {
        $known = [...self::OWN_KEYS, ...array_keys($this->sectionMap())];

        return array_values(array_diff(array_map(strval(...), array_keys($kit)), $known));
    }

    /**
     * Apply the kit: upsert pages, templates, menus, and settings. Runs in
     * one transaction — a kit applies whole or not at all.
     *
     * @param  array<string, mixed>  $kit
     * @return array<string, int> counts per action
     */
    public function apply(array $kit, ?string $actorId = null): array
    {
        self::assertKit($kit);

        return DB::transaction(function () use ($kit, $actorId): array {
            $counts = ['created' => 0, 'updated' => 0, 'menus' => 0, 'patterns' => 0];

            foreach (['page' => 'pages', 'pages_template' => 'templates'] as $type => $key) {
                foreach ($this->kitEntries($kit, $key) as $row) {
                    $this->applyEntry($type, $row, $actorId, $counts);
                }
            }

            foreach ($this->kitMenus($kit) as $menuRow) {
                $menu = Menu::query()->firstOrCreate(
                    ['handle' => $menuRow['handle']],
                    ['name' => $menuRow['name']],
                );
                $this->menus->syncItems($menu, $this->slugs->mapMenuTree($menuRow['tree']), $actorId);
                $counts['menus']++;
            }

            $counts['patterns'] = $this->applyPatterns($kit);

            // Plugin sections ride the same transaction: a kit applies whole
            // or not at all, and that includes the entities its pages
            // reference. Keys are namespaced ('forms:forms'), so they can
            // never collide with the counts above.
            foreach ($this->sectionMap() as $key => $section) {
                if (array_key_exists($key, $kit)) {
                    $counts[$key] = $section->apply($this->kitSectionRows($kit, $key), $actorId);
                }
            }

            $this->applySettings($kit);

            return $counts;
        });
    }

    /**
     * Apply the kit adding only what this site does not have — a theme's
     * demo content, imported on install (Magna\Themes\ThemeContentImporter).
     *
     * apply() is a sync: it upserts, so it can overwrite an edited page, and
     * it writes maintenance_mode. Demo content arrives on sites that already
     * have content, so here nothing that exists is touched:
     *
     *   • pages and templates are created when their slug+locale is free,
     *     otherwise skipped;
     *   • a menu is filled only when its handle does not exist or is empty;
     *   • patterns are added under names not already taken;
     *   • plugin sections receive only the rows their own diff() reports as
     *     added, so a section's upsert never meets an existing record;
     *   • the home and not-found pages are set only when unset; maintenance
     *     mode and collection mounts are never changed.
     *
     * One transaction, like apply(): demo content lands whole or not at all.
     *
     * @param  array<string, mixed>  $kit
     * @return array<string, int> created per kind, and "skipped"
     */
    public function applyMissing(array $kit, ?string $actorId = null): array
    {
        self::assertKit($kit);

        return DB::transaction(function () use ($kit, $actorId): array {
            $counts = ['created' => 0, 'menus' => 0, 'patterns' => 0, 'skipped' => 0];

            foreach (['page' => 'pages', 'pages_template' => 'templates'] as $type => $key) {
                foreach ($this->kitEntries($kit, $key) as $row) {
                    $slug = $row['slug'] ?? null;
                    $locale = is_string($row['locale'] ?? null) ? $row['locale'] : '';

                    if (is_string($slug) && $slug !== '' && Entry::type($type)->where('slug', $slug)->where('locale', $locale)->exists()) {
                        $counts['skipped']++;

                        continue;
                    }

                    $this->applyEntry($type, $row, $actorId, $counts);
                }
            }

            foreach ($this->kitMenus($kit) as $menuRow) {
                $menu = Menu::query()->where('handle', $menuRow['handle'])->first();

                if ($menu !== null && $menu->items()->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                $menu ??= Menu::query()->create(['handle' => $menuRow['handle'], 'name' => $menuRow['name']]);
                $this->menus->syncItems($menu, $this->slugs->mapMenuTree($menuRow['tree']), $actorId);
                $counts['menus']++;
            }

            $counts['patterns'] = $this->applyPatterns([
                'patterns' => array_values(array_filter(
                    is_array($kit['patterns'] ?? null) ? $kit['patterns'] : [],
                    fn ($row): bool => is_array($row) && is_string($row['name'] ?? null)
                        && ! Pattern::query()->where('name', trim($row['name']))->exists(),
                )),
            ]);

            foreach ($this->sectionMap() as $key => $section) {
                if (! array_key_exists($key, $kit)) {
                    continue;
                }

                $new = [];
                foreach ($this->kitSectionRows($kit, $key) as $row) {
                    // Asked row by row: diff() names what it would add, and a
                    // row it does not call "added" exists here already.
                    if (($section->diff([$row])['added'] ?? []) !== []) {
                        $new[] = $row;
                    } else {
                        $counts['skipped']++;
                    }
                }

                $counts[$key] = $new === [] ? 0 : $section->apply($new, $actorId);
            }

            $this->applyMissingSettings($kit);

            return $counts;
        });
    }

    /**
     * The builder's saved patterns.
     *
     * A pattern is how a composite — a mocked-up terminal, a pricing table
     * with its band — stops being something an editor must rebuild by hand
     * every time. Until this key existed a pattern could only be born from a
     * user's own selection, so a theme could ship the markup for a composite
     * and no way to use it twice.
     *
     * Keyed by NAME on import, like everything else here is keyed by slug:
     * ids are environment facts.
     *
     * @return list<array<string, mixed>>
     */
    private function exportPatterns(): array
    {
        return Pattern::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Pattern $pattern): array => [
                'name' => (string) $pattern->name,
                'kind' => (string) $pattern->kind,
                'document' => $pattern->document,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportEntries(string $type): array
    {
        if (! $this->schemas->has($type)) {
            return [];
        }

        $rows = [];
        foreach (Entry::type($type)->orderBy('slug')->orderBy('locale')->get() as $entry) {
            $rows[] = KitComparer::entryRow($entry);
        }

        return $rows;
    }

    /** @return list<array{handle: string, name: string, tree: list<array<string, mixed>>}> */
    private function exportMenus(): array
    {
        $menus = [];
        foreach (Menu::query()->orderBy('handle')->get() as $menu) {
            $menus[] = [
                'handle' => $menu->handle,
                'name' => $menu->name,
                'tree' => $this->menus->exportTree($menu),
            ];
        }

        return $menus;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $counts
     */
    private function applyEntry(string $type, array $row, ?string $actorId, array &$counts): void
    {
        $slug = $row['slug'] ?? null;
        if (! is_string($slug) || $slug === '') {
            return;
        }
        $locale = is_string($row['locale'] ?? null) ? $row['locale'] : '';

        $data = array_intersect_key($row, array_flip(KitComparer::ENTRY_KEYS));

        /** @var Entry|null $existing */
        $existing = Entry::type($type)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();

        if ($existing !== null) {
            unset($data['translation_group']); // linkage is an env fact once rows exist
            $entry = $this->entries->update($existing, $data, $actorId);
            $counts['updated']++;
        } else {
            $entry = $this->entries->create($type, $data, $actorId);
            $this->adoptTranslationGroup($type, $entry, $slug);
            $counts['created']++;
        }

        if (($row['status'] ?? null) === 'published' && ! $entry->isPublished()) {
            $this->entries->publish($entry, actorId: $actorId);
        }
    }

    /**
     * Link a newly created translation to its fallback-locale twin.
     *
     * A kit links translations by slug: `features` in `ml` IS the Malayalam
     * `features` page. EntryManager::create() roots every new entry in a
     * translation group of its own id, and its validator strips a
     * translation_group a kit might carry, so without this every localized
     * row is an orphan: no hreflang pair, no language switcher, and a
     * localized home page that resolves to nothing. Written straight to the
     * model for the same reason the validator would refuse it.
     *
     * Only on create: once rows exist, their linkage is the site's own.
     */
    private function adoptTranslationGroup(string $type, Entry $entry, string $slug): void
    {
        if (! array_key_exists('translation_group', $entry->getAttributes())) {
            return; // a type without translations
        }

        $fallback = LocalizationSettings::get()->fallback_locale;
        $locale = $entry->getAttribute('locale');
        if (! is_string($locale) || $locale === '' || $locale === $fallback) {
            return;
        }

        /** @var Entry|null $twin */
        $twin = Entry::type($type)
            ->where('slug', $slug)
            ->where(fn ($query) => $query->where('locale', $fallback)->orWhere('locale', '')->orWhereNull('locale'))
            ->whereKeyNot($entry->getKey())
            ->orderBy('id')
            ->first();
        if ($twin === null) {
            return;
        }

        $group = $twin->getAttribute('translation_group');
        if (! is_string($group) || $group === '') {
            // A row from before the backfill: root the group at the twin.
            $group = (string) $twin->getKey();
            $twin->translation_group = $group;
            $twin->save();
        }

        $entry->translation_group = $group;
        $entry->save();
    }

    /**
     * Upsert the kit's patterns by name.
     *
     * Validated through PatternManager, the same gate a user's own "save as
     * pattern" goes through — a kit is a file somebody can edit, so it earns
     * no more trust than the builder does. An invalid one is skipped rather
     * than aborting the sync: the rest of the site is still worth applying,
     * and a pattern nobody can insert is a smaller problem than a half-applied
     * bundle.
     *
     * @param  array<string, mixed>  $kit
     * @return int how many were written
     */
    private function applyPatterns(array $kit): int
    {
        $rows = $kit['patterns'] ?? null;

        if (! is_array($rows)) {
            return 0;
        }

        $written = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['kind'] ?? null) || ! is_array($row['document'] ?? null)) {
                continue;
            }

            try {
                $this->patterns->upsertNamed($row['name'], $row['kind'], $row['document']);
                $written++;
            } catch (\Throwable) {
                // Reported by diff; a bad pattern does not cost the site.
            }
        }

        return $written;
    }

    /**
     * The settings half of applyMissing(): the home and not-found pages only
     * where none is set. Maintenance mode and collection mounts are a site's
     * own business and a demo never changes them.
     *
     * @param  array<string, mixed>  $kit
     */
    private function applyMissingSettings(array $kit): void
    {
        $kitSettings = is_array($kit['settings'] ?? null) ? $kit['settings'] : [];
        $settings = PagesSettings::get();
        $changed = false;

        if ($settings->home_page_id === null && ($home = $this->slugs->idForSlug($kitSettings['home_page_slug'] ?? null)) !== null) {
            $settings->home_page_id = $home;
            $changed = true;
        }

        if ($settings->not_found_page_id === null && ($notFound = $this->slugs->idForSlug($kitSettings['not_found_page_slug'] ?? null)) !== null) {
            $settings->not_found_page_id = $notFound;
            $changed = true;
        }

        if ($changed) {
            app(SettingsRepository::class)->persist($settings);
        }
    }

    /** @param array<string, mixed> $kit */
    private function applySettings(array $kit): void
    {
        $kitSettings = is_array($kit['settings'] ?? null) ? $kit['settings'] : [];
        $settings = PagesSettings::get();

        $settings->home_page_id = $this->slugs->idForSlug($kitSettings['home_page_slug'] ?? null) ?? $settings->home_page_id;
        $settings->not_found_page_id = $this->slugs->idForSlug($kitSettings['not_found_page_slug'] ?? null) ?? $settings->not_found_page_id;
        // Only when the kit says so: a kit that does not mention maintenance
        // mode (a theme update's partial kit, or an older export) used to
        // switch a site in maintenance back on to the public.
        if (array_key_exists('maintenance_mode', $kitSettings)) {
            $settings->maintenance_mode = (bool) $kitSettings['maintenance_mode'];
        }
        if (is_array($kitSettings['collection_mounts'] ?? null)) {
            /** @var list<array{type: string, prefix: string}> $mounts */
            $mounts = array_values(array_filter($kitSettings['collection_mounts'], 'is_array'));
            $settings->collection_mounts = $mounts;
        }

        app(SettingsRepository::class)->persist($settings);
    }

    /**
     * @param  list<array<string, mixed>>  $kitRows
     * @return array<string, list<string>>
     */
    private function diffEntries(string $type, array $kitRows): array
    {
        $local = [];
        if ($this->schemas->has($type)) {
            foreach (Entry::type($type)->get() as $entry) {
                $slug = $entry->getAttribute('slug');
                $locale = $entry->getAttribute('locale');
                if (is_string($slug)) {
                    $local[$slug.'@'.(is_string($locale) ? $locale : '')] = KitComparer::entryRow($entry);
                }
            }
        }

        $added = $changed = [];
        $seen = [];
        foreach ($kitRows as $row) {
            $slug = $row['slug'] ?? null;
            if (! is_string($slug)) {
                continue;
            }
            $key = $slug.'@'.(is_string($row['locale'] ?? null) ? $row['locale'] : '');
            $seen[] = $key;

            if (! array_key_exists($key, $local)) {
                $added[] = $key;
            } elseif (KitComparer::compareTo($local[$key], $row) !== KitComparer::entryStateOf($row)) {
                $changed[] = $key;
            }
        }

        return [
            'added' => $added,
            'changed' => $changed,
            'localOnly' => array_values(array_diff(array_keys($local), $seen)),
        ];
    }

    /**
     * @param  list<array{handle: string, name: string, tree: list<array<string, mixed>>}>  $kitMenus
     * @return array<string, list<string>>
     */
    private function diffMenus(array $kitMenus): array
    {
        $localHandles = Menu::query()->pluck('handle')->all();
        $kitHandles = array_column($kitMenus, 'handle');

        return [
            'added' => array_values(array_diff($kitHandles, $localHandles)),
            // Menu trees always sync (idempotent replace) — flag presence only.
            'changed' => array_values(array_intersect($kitHandles, $localHandles)),
            'localOnly' => array_values(array_diff($localHandles, $kitHandles)),
        ];
    }

    /** The home and not-found pages as slugs — what a kit's settings name. */
    public function localSettingSlug(string $kitKey): ?string
    {
        $settings = PagesSettings::get();

        return match ($kitKey) {
            'home_page_slug' => $this->slugs->slugForId($settings->home_page_id),
            'not_found_page_slug' => $this->slugs->slugForId($settings->not_found_page_id),
            default => null,
        };
    }

    /**
     * The registered plugin sections, by kit key.
     *
     * @return array<string, SiteKitSection>
     */
    public function sections(): array
    {
        return $this->sectionMap();
    }

    /** @param array<string, mixed> $kit */
    private function kitEntries(array $kit, string $key): array
    {
        /** @var list<array<string, mixed>> */
        return is_array($kit[$key] ?? null) ? array_values(array_filter($kit[$key], 'is_array')) : [];
    }

    /** @param array<string, mixed> $kit */
    private function kitMenus(array $kit): array
    {
        /** @var list<array{handle: string, name: string, tree: list<array<string, mixed>>}> */
        return is_array($kit['menus'] ?? null)
            ? array_values(array_filter(
                $kit['menus'],
                fn ($m): bool => is_array($m) && is_string($m['handle'] ?? null) && is_string($m['name'] ?? null) && is_array($m['tree'] ?? null),
            ))
            : [];
    }

    /**
     * The registered plugin sections, keyed and validated. A key without a
     * colon could shadow a kit key this class grows later, and two plugins
     * claiming one key would silently swallow each other's rows — both are
     * registration bugs, so both throw at first use rather than skip.
     *
     * @return array<string, SiteKitSection>
     */
    private function sectionMap(): array
    {
        $map = [];
        foreach ($this->sections as $section) {
            $key = $section->key();
            if (! str_contains($key, ':') || in_array($key, self::OWN_KEYS, true)) {
                throw new \LogicException("Site-kit section key [{$key}] must be namespaced 'plugin:entity'.");
            }
            if (isset($map[$key])) {
                throw new \LogicException("Site-kit section key [{$key}] is registered twice.");
            }
            $map[$key] = $section;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, mixed>  $kit
     * @return list<array<string, mixed>>
     */
    private function kitSectionRows(array $kit, string $key): array
    {
        return is_array($kit[$key] ?? null) ? array_values(array_filter($kit[$key], 'is_array')) : [];
    }
}
