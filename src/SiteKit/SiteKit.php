<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\SchemaRegistry;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
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
 */
class SiteKit
{
    public const VERSION = 1;

    private const ENTRY_KEYS = ['title', 'slug', 'path', 'locale', 'translation_group', 'template', 'kind', 'meta_title', 'meta_description', 'blocks_data'];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly EntryManager $entries,
        private readonly MenuManager $menus,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $settings = PagesSettings::get();

        return [
            'format' => 'magna-site-kit',
            'version' => self::VERSION,
            'pages' => $this->exportEntries('page'),
            'templates' => $this->exportEntries('pages_template'),
            'menus' => $this->exportMenus(),
            'settings' => [
                'home_page_slug' => $this->slugForId($settings->home_page_id),
                'not_found_page_slug' => $this->slugForId($settings->not_found_page_id),
                'maintenance_mode' => $settings->maintenance_mode,
                'collection_mounts' => $settings->collection_mounts,
            ],
        ];
    }

    /**
     * What applying the kit would change, without changing it.
     *
     * @param  array<string, mixed>  $kit
     * @return array{pages: array<string, list<string>>, templates: array<string, list<string>>, menus: array<string, list<string>>}
     */
    public function diff(array $kit): array
    {
        return [
            'pages' => $this->diffEntries('page', $this->kitEntries($kit, 'pages')),
            'templates' => $this->diffEntries('pages_template', $this->kitEntries($kit, 'templates')),
            'menus' => $this->diffMenus($this->kitMenus($kit)),
        ];
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
        if (($kit['format'] ?? null) !== 'magna-site-kit' || ($kit['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Not a magna-site-kit v'.self::VERSION.' bundle.');
        }

        return DB::transaction(function () use ($kit, $actorId): array {
            $counts = ['created' => 0, 'updated' => 0, 'menus' => 0];

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
                $this->menus->syncItems($menu, $this->mapMenuTree($menuRow['tree']), $actorId);
                $counts['menus']++;
            }

            $this->applySettings($kit);

            return $counts;
        });
    }

    /** @return list<array<string, mixed>> */
    private function exportEntries(string $type): array
    {
        if (! $this->schemas->has($type)) {
            return [];
        }

        $rows = [];
        foreach (Entry::type($type)->orderBy('slug')->orderBy('locale')->get() as $entry) {
            $row = ['status' => $entry->status->value];
            foreach (self::ENTRY_KEYS as $key) {
                $value = $entry->getAttribute($key);
                if ($value !== null) {
                    $row[$key] = $value;
                }
            }
            $rows[] = $row;
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

        $data = array_intersect_key($row, array_flip(self::ENTRY_KEYS));

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
            $counts['created']++;
        }

        if (($row['status'] ?? null) === 'published' && ! $entry->isPublished()) {
            $this->entries->publish($entry, actorId: $actorId);
        }
    }

    /** @param array<string, mixed> $kit */
    private function applySettings(array $kit): void
    {
        $kitSettings = is_array($kit['settings'] ?? null) ? $kit['settings'] : [];
        $settings = PagesSettings::get();

        $settings->home_page_id = $this->idForSlug($kitSettings['home_page_slug'] ?? null) ?? $settings->home_page_id;
        $settings->not_found_page_id = $this->idForSlug($kitSettings['not_found_page_slug'] ?? null) ?? $settings->not_found_page_id;
        $settings->maintenance_mode = (bool) ($kitSettings['maintenance_mode'] ?? false);
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
                    $local[$slug.'@'.(is_string($locale) ? $locale : '')] = $this->comparable([
                        'status' => $entry->status->value,
                        ...array_filter(
                            array_combine(self::ENTRY_KEYS, array_map($entry->getAttribute(...), self::ENTRY_KEYS)),
                            fn ($v): bool => $v !== null,
                        ),
                    ]);
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
            } elseif ($local[$key] !== $this->comparable($row)) {
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

    /**
     * Normalised comparison form: translation_group excluded (an env fact),
     * key order fixed.
     *
     * @param  array<string, mixed>  $row
     */
    private function comparable(array $row): string
    {
        unset($row['translation_group']);
        ksort($row);

        return (string) json_encode($row);
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
     * Kit menu trees reference pages by slug; syncItems wants local ids.
     * A slug with no local page degrades that item to a linkless entry —
     * resolution drops it from the rendered nav, same as a deleted page.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    private function mapMenuTree(array $tree): array
    {
        $mapped = [];
        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (is_string($node['page_slug'] ?? null)) {
                $node['page_id'] = $this->idForSlug($node['page_slug']);
                unset($node['page_slug']);
            }
            if (is_array($node['children'] ?? null)) {
                $node['children'] = $this->mapMenuTree(array_values($node['children']));
            }
            $mapped[] = $node;
        }

        return $mapped;
    }

    private function slugForId(?string $id): ?string
    {
        if ($id === null || $id === '' || ! $this->schemas->has('page')) {
            return null;
        }
        $slug = Entry::type('page')->whereKey($id)->first()?->getAttribute('slug');

        return is_string($slug) ? $slug : null;
    }

    private function idForSlug(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '' || ! $this->schemas->has('page')) {
            return null;
        }
        $id = Entry::type('page')->where('slug', $slug)->first()?->getKey();

        return is_string($id) ? $id : null;
    }
}
