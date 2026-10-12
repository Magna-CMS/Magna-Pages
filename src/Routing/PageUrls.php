<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Settings\LocalizationSettings;

/**
 * Published-page URL resolution, in one place.
 *
 * Three callers used to carry their own copy of "path when set, slug
 * otherwise, '/' in front": the menu resolver, the renderer's homeUrl,
 * and (the reason this class exists) every theme layout that needed a
 * link and had nothing to ask — the layout contract's `pageUrl` closure
 * is backed by {@see forSlug}. One resolver means a page renamed in the
 * builder moves everywhere at once.
 */
class PageUrls
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly LocalePrefix $localePrefix,
    ) {}

    /**
     * Site-relative URLs for the referenced ids — one query for the lot.
     *
     * Only PUBLISHED pages resolve: a menu item or wordmark pointing at a
     * draft must degrade, never 404. URLs are bare (no locale prefix) —
     * the shape menus have always emitted.
     *
     * @param  list<string>  $pageIds
     * @return array<string, string> id => URL
     */
    public function forIds(array $pageIds): array
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
            $segment = $this->segmentFor($entry);
            if (is_string($id) && $segment !== '') {
                $urls[$id] = '/'.$segment;
            }
        }

        return $urls;
    }

    /**
     * The URL a theme should link for a page slug, or null.
     *
     * Null for a draft, trashed or unknown slug — the caller decides how a
     * missing target degrades (hide the link, fall back to '/'). A page in
     * a non-fallback locale resolves under its locale prefix, because that
     * is the only address the router serves it at; when translations share
     * a slug the fallback locale's page wins, so a layout link is stable
     * whichever locale the visitor is reading.
     */
    public function forSlug(string $slug): ?string
    {
        if ($slug === '' || ! $this->schemaRegistry->has('page')) {
            return null;
        }

        $pages = Entry::type('page')
            ->where('slug', $slug)
            ->where('status', EntryStatus::Published->value)
            ->orderBy('locale')
            ->get();

        if ($pages->isEmpty()) {
            return null;
        }

        $fallback = LocalizationSettings::get()->fallback_locale;

        $page = $pages->first(function (Entry $entry) use ($fallback): bool {
            $locale = $entry->getAttribute('locale');

            // '' is how EntryManager stores "the fallback locale".
            return ! is_string($locale) || $locale === '' || $locale === $fallback;
        }) ?? $pages->first();

        $segment = $this->segmentFor($page);
        if ($segment === '') {
            return null;
        }

        $locale = $page->getAttribute('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : $fallback;

        return $this->localePrefix->urlFor($locale, $segment);
    }

    /** The page's address segment: its nested path when set, its slug otherwise. */
    private function segmentFor(Entry $entry): string
    {
        $path = $entry->getAttribute('path');
        $slug = $entry->getAttribute('slug');

        return is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');
    }
}
