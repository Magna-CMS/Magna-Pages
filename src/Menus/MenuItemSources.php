<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Frontend\FrontendPageRegistry;

/**
 * What the builder's "Add menu items" panels offer.
 *
 * Kept apart from the page so the panels are a query question rather than
 * a Livewire one: the page asks for a list and renders it, and what counts
 * as linkable is decided in one place instead of once per panel.
 *
 * Each source returns rows the panel can check off in bulk, which is the
 * gesture that makes building a menu quick — adding twelve pages one field
 * at a time is why the old screen was slow to use.
 */
final class MenuItemSources
{
    /** A panel lists this many at once; search narrows rather than pages. */
    public const LIMIT = 50;

    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly FrontendPageRegistry $frontendPages,
    ) {}

    /**
     * Published pages, newest first — the order WordPress's own panel uses,
     * because the thing you just wrote is the thing you are most likely to
     * be linking.
     *
     * @return list<array{id: string, label: string, meta: string}>
     */
    public function pages(string $search = ''): array
    {
        if (! $this->schemaRegistry->has('page')) {
            return [];
        }

        $query = Entry::type('page')->where('status', EntryStatus::Published->value);

        $search = trim($search);
        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $rows = [];
        foreach ($query->orderByDesc('updated_at')->limit(self::LIMIT)->get() as $entry) {
            $id = $entry->getKey();
            $title = $entry->getAttribute('title');
            if (! is_string($id) || ! is_string($title)) {
                continue;
            }

            $path = $entry->getAttribute('path');
            $slug = $entry->getAttribute('slug');
            $segment = is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');

            $rows[] = ['id' => $id, 'label' => $title, 'meta' => $segment === '' ? '' : '/'.$segment];
        }

        return $rows;
    }

    /**
     * Plugin frontend pages that asked to be menu-visible.
     *
     * @return list<array{id: string, label: string, meta: string}>
     */
    public function pluginPages(string $search = ''): array
    {
        $search = mb_strtolower(trim($search));

        $rows = [];
        foreach ($this->frontendPages->all() as $name => $page) {
            if (! $page->menuVisible) {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower($page->title), $search)) {
                continue;
            }

            $rows[] = ['id' => $name, 'label' => $page->title, 'meta' => '/'.$page->normalizedPath()];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }
}
