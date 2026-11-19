<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;
use Magna\Settings\LocalizationSettings;

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

    /**
     * The fallback-locale page with this slug.
     *
     * A localized site keeps English slugs for every locale (`/ml/features`
     * is the `features` page in Malayalam), so one slug names one page per
     * locale. What a kit names by slug — the home page, the not-found page —
     * is the site's own setting, and the site's own pages are the fallback
     * locale's. An unscoped, unordered lookup could return the Malayalam row
     * and bind it as the home page, serving Malayalam at bare `/`.
     */
    public function idForSlug(mixed $slug): ?string
    {
        if (! is_string($slug) || $slug === '' || ! $this->schemas->has('page')) {
            return null;
        }
        $fallback = LocalizationSettings::get()->fallback_locale;

        $id = Entry::type('page')
            ->where('slug', $slug)
            // '' (and null on old rows) is how EntryManager stores "the
            // fallback locale" when a create names none.
            ->where(fn ($query) => $query->where('locale', $fallback)->orWhere('locale', '')->orWhereNull('locale'))
            ->orderBy('id')
            ->first()?->getKey();

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
