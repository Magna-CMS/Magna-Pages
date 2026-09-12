<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Frontend\FrontendPageVisibility;

/**
 * Menu lifecycle + resolution.
 *
 * Saving replaces a menu's item tree transactionally and snapshots the full
 * tree into pages_menu_revisions (append-only — the "menus are versioned"
 * guarantee). Resolution produces the nested link tree the nav block and the
 * menus API serve: page items resolve to their published page's URL, and an
 * item whose page is gone or unpublished is silently skipped — a deleted
 * page must degrade the nav, never crash it.
 */
class MenuManager
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly FrontendPageRegistry $frontendPages,
        private readonly FrontendPageVisibility $frontendVisibility,
    ) {}

    public function create(string $handle, string $name): Menu
    {
        return Menu::query()->create(['handle' => $handle, 'name' => $name]);
    }

    /**
     * Replace the menu's items with the given tree and snapshot the result.
     *
     * Tree shape (recursive):
     * [
     *   ['label' => 'Home', 'type' => 'page', 'page_id' => '01H…'],
     *   ['label' => 'Docs', 'type' => 'url', 'url' => '/docs', 'children' => [...]],
     * ]
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function syncItems(Menu $menu, array $tree, ?string $authorId = null): void
    {
        DB::transaction(function () use ($menu, $tree, $authorId): void {
            $menu->items()->delete();
            $this->insertLevel($menu, $tree, parentId: null);

            DB::table('pages_menu_revisions')->insert([
                'id' => (string) Str::ulid(),
                'menu_id' => $menu->id,
                'payload' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'author_id' => $authorId,
                'created_at' => now(),
            ]);
        });

        // Menus render on every page (header nav) — sitewide blast radius.
        app(PageCache::class)->flush();
    }

    /**
     * The resolved link tree for a menu handle (empty when unknown).
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(string $handle): array
    {
        $menu = Menu::query()->where('handle', $handle)->first();
        if ($menu === null) {
            return [];
        }

        $items = $menu->items()->get();
        $pageUrls = $this->pageUrlsFor(
            $items->where('type', MenuItem::TYPE_PAGE)->pluck('page_id')->filter()->values()->all()
        );

        return $this->buildLevel($items->all(), null, $pageUrls);
    }

    /**
     * The menu's item tree in syncItems() shape, but with page references
     * expressed as SLUGS instead of ids — the site-kit's cross-environment
     * form. SiteKit maps slugs back to local ids before syncing.
     *
     * @return list<array<string, mixed>>
     */
    public function exportTree(Menu $menu): array
    {
        $items = $menu->items()->get()->all();

        $pageSlugs = [];
        if ($this->schemaRegistry->has('page')) {
            $ids = array_values(array_filter(array_map(
                fn (MenuItem $i): ?string => $i->page_id,
                $items,
            )));
            if ($ids !== []) {
                foreach (Entry::type('page')->whereIn('id', $ids)->get() as $page) {
                    $id = $page->getKey();
                    $slug = $page->getAttribute('slug');
                    if (is_string($id) && is_string($slug)) {
                        $pageSlugs[$id] = $slug;
                    }
                }
            }
        }

        $build = function (?string $parentId) use (&$build, $items, $pageSlugs): array {
            $level = [];
            foreach ($items as $item) {
                if ($item->parent_id !== $parentId) {
                    continue;
                }
                $node = [
                    'label' => $item->label,
                    'type' => $item->type,
                    'url' => $item->url,
                    'target' => $item->target,
                    'settings' => $item->settings,
                ];
                if ($item->page_id !== null && isset($pageSlugs[$item->page_id])) {
                    $node['page_slug'] = $pageSlugs[$item->page_id];
                }
                $children = $build($item->id);
                if ($children !== []) {
                    $node['children'] = $children;
                }
                $level[] = $node;
            }

            return $level;
        };

        return $build(null);
    }

    /**
     * @param  list<array<string, mixed>>  $level
     */
    private function insertLevel(Menu $menu, array $level, ?string $parentId): void
    {
        $position = 0;
        foreach ($level as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = in_array($node['type'] ?? null, [MenuItem::TYPE_PAGE, MenuItem::TYPE_PLUGIN], true)
                ? $node['type']
                : MenuItem::TYPE_URL;

            // A plugin item references its frontend page by NAME — stable
            // across the plugin re-mounting the page at a different path.
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            if ($type === MenuItem::TYPE_PLUGIN && is_string($node['plugin_page'] ?? null)) {
                $settings['frontend_page'] = $node['plugin_page'];
            }

            // The builder sends every optional field on every item, so the
            // blank ones are dropped rather than stored: an absent key is
            // what "not set" means to the renderer, and a row of empty
            // strings makes every diff of a revision look like a change.
            $settings = array_filter(
                $settings,
                static fn (mixed $value): bool => $value !== '' && $value !== null,
            );

            $item = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                'position' => $position++,
                'label' => is_string($node['label'] ?? null) ? $node['label'] : '',
                'type' => $type,
                'page_id' => is_string($node['page_id'] ?? null) ? $node['page_id'] : null,
                'url' => is_string($node['url'] ?? null) ? $node['url'] : null,
                'target' => is_string($node['target'] ?? null) ? $node['target'] : null,
                'settings' => $settings === [] ? null : $settings,
            ]);

            if (is_array($node['children'] ?? null)) {
                $this->insertLevel($menu, array_values($node['children']), $item->id);
            }
        }
    }

    /**
     * @param  list<MenuItem>  $items
     * @param  array<string, string>  $pageUrls
     * @return list<array<string, mixed>>
     */
    private function buildLevel(array $items, ?string $parentId, array $pageUrls): array
    {
        $level = [];

        foreach ($items as $item) {
            if ($item->parent_id !== $parentId) {
                continue;
            }

            $label = $item->label;

            if ($item->type === MenuItem::TYPE_PLUGIN) {
                // Plugin item: URL from the registered frontend page. Gone
                // plugin degrades the item away; an auth-gated page hides
                // from visitors who may not open it (cache-safe because
                // only guest renders enter the shared page cache).
                $name = is_string($item->settings['frontend_page'] ?? null)
                    ? $item->settings['frontend_page']
                    : '';
                $page = $name === '' ? null : $this->frontendPages->get($name);
                if ($page === null || ! $this->frontendVisibility->visibleTo($page, auth()->user())) {
                    continue;
                }
                $url = '/'.$page->normalizedPath();
                $label = $label !== '' ? $label : $page->title;
            } else {
                $url = $item->type === MenuItem::TYPE_PAGE
                    ? ($pageUrls[$item->page_id ?? ''] ?? null)
                    : $item->url;

                // A page item whose page is gone or unpublished degrades by
                // disappearing from the nav.
                if ($item->type === MenuItem::TYPE_PAGE && $url === null) {
                    continue;
                }
            }

            $settings = $item->settings ?? [];

            $level[] = [
                'label' => $label,
                'url' => $url ?? '#',
                'target' => $item->target,
                // Per-item presentation the builder collects. Absent keys
                // stay absent: the nav view prints an attribute only when
                // there is something to print.
                'title_attr' => is_string($settings['title_attr'] ?? null) ? $settings['title_attr'] : null,
                'css_class' => is_string($settings['css_class'] ?? null) ? $settings['css_class'] : null,
                'rel' => is_string($settings['rel'] ?? null) ? $settings['rel'] : null,
                'description' => is_string($settings['description'] ?? null) ? $settings['description'] : null,
                'children' => $this->buildLevel($items, $item->id, $pageUrls),
            ];
        }

        return $level;
    }

    /**
     * Published-page slugs for the referenced ids, as site-relative URLs —
     * one query for the whole menu.
     *
     * @param  list<string>  $pageIds
     * @return array<string, string>
     */
    private function pageUrlsFor(array $pageIds): array
    {
        if ($pageIds === [] || ! $this->schemaRegistry->has('page')) {
            return [];
        }

        $urls = [];
        foreach (Entry::type('page')
            ->whereIn('id', $pageIds)
            ->where('status', EntryStatus::Published->value)
            ->get() as $entry) {
            $id = $entry->getKey();
            $path = $entry->getAttribute('path');
            $slug = $entry->getAttribute('slug');
            $segment = is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');
            if (is_string($id) && $segment !== '') {
                $urls[$id] = '/'.$segment;
            }
        }

        return $urls;
    }
}
