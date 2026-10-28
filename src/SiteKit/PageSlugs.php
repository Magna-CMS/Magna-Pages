<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;

/**
 * Pages by slug, the way a site kit names them: ids are environment facts,
 * slugs are the site's own names, so everything a kit cross-references —
 * the home page, a menu item's page — goes through here.
 */
final class PageSlugs
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    public function slugForId(?string $id): ?string
    {
        if ($id === null || $id === '' || ! $this->schemas->has('page')) {
            return null;
        }
        $slug = Entry::type('page')->whereKey($id)->first()?->getAttribute('slug');

        return is_string($slug) ? $slug : null;
    }

    public function idForSlug(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '' || ! $this->schemas->has('page')) {
            return null;
        }
        $id = Entry::type('page')->where('slug', $slug)->first()?->getKey();

        return is_string($id) ? $id : null;
    }

    /**
     * Kit menu trees reference pages by slug; syncItems wants local ids.
     * A slug with no local page degrades that item to a linkless entry —
     * resolution drops it from the rendered nav, same as a deleted page.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    public function mapMenuTree(array $tree): array
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
}
