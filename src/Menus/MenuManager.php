<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Pages\Cache\PageCache;

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
    public function __construct(private readonly SchemaRegistry $schemaRegistry) {}

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
     * @param  list<array<string, mixed>>  $level
     */
    private function insertLevel(Menu $menu, array $level, ?string $parentId): void
    {
        $position = 0;
        foreach ($level as $node) {
            if (! is_array($node)) {
                continue;
            }

            $item = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                'position' => $position++,
                'label' => is_string($node['label'] ?? null) ? $node['label'] : '',
                'type' => ($node['type'] ?? null) === MenuItem::TYPE_PAGE ? MenuItem::TYPE_PAGE : MenuItem::TYPE_URL,
                'page_id' => is_string($node['page_id'] ?? null) ? $node['page_id'] : null,
                'url' => is_string($node['url'] ?? null) ? $node['url'] : null,
                'target' => is_string($node['target'] ?? null) ? $node['target'] : null,
                'settings' => is_array($node['settings'] ?? null) ? $node['settings'] : null,
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

            $url = $item->type === MenuItem::TYPE_PAGE
                ? ($pageUrls[$item->page_id ?? ''] ?? null)
                : $item->url;

            // A page item whose page is gone or unpublished degrades by
            // disappearing from the nav.
            if ($item->type === MenuItem::TYPE_PAGE && $url === null) {
                continue;
            }

            $level[] = [
                'label' => $item->label,
                'url' => $url ?? '#',
                'target' => $item->target,
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
