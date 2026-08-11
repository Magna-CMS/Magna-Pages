<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Illuminate\Database\Eloquent\Builder;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Pages\PagesSettings;
use Magna\Settings\LocalizationSettings;

/**
 * Resolves a request path to a published page entry.
 *
 * v1 scope: root (the configured home page) and single-segment slugs.
 * Hierarchical paths, locale prefixes, mounted collections, plugin frontend
 * pages, and the redirect table are the next routing milestones
 * (docs/magna-pages/08-BUILD-PHASES.md Phase A item 6) and slot in here —
 * this class is the single URL→entry seam.
 */
final class PageRouteResolver
{
    public function __construct(private readonly SchemaRegistry $schemaRegistry) {}

    public function resolve(string $path, PagesSettings $settings, ?string $locale = null): ?Entry
    {
        if (! $this->schemaRegistry->has('page')) {
            return null;
        }

        $path = trim($path, '/');

        if ($path === '') {
            // The home page in a non-fallback locale is its translation —
            // same translation_group, that locale.
            $home = $this->publishedById($settings->home_page_id);
            if ($home !== null && $locale !== null) {
                return $this->translationOf($home, $locale);
            }

            return $home;
        }

        // Materialized path first (nested URLs, one indexed read); slug as
        // the fallback for rows created before the hierarchy backfill.
        /** @var Entry|null $entry */
        $entry = $this->localized(Entry::type('page'), $locale)
            ->where('path', $path)
            ->where('status', EntryStatus::Published->value)
            ->first();

        if ($entry !== null) {
            return $entry;
        }

        if (str_contains($path, '/')) {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = $this->localized(Entry::type('page'), $locale)
            ->whereNull('path')
            ->where('slug', $path)
            ->where('status', EntryStatus::Published->value)
            ->first();

        return $entry;
    }

    /**
     * Scope a page query to the requested locale. Unprefixed URLs (locale
     * null) serve the fallback locale — rows whose locale matches it, plus
     * rows with no locale set at all (EntryManager stores '' when a create
     * names none, which is most single-language sites).
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function localized(Builder $query, ?string $locale)
    {
        if ($locale !== null) {
            return $query->where('locale', $locale);
        }

        $fallback = LocalizationSettings::get()->fallback_locale;

        return $query->where(function ($q) use ($fallback): void {
            $q->where('locale', $fallback)->orWhere('locale', '')->orWhereNull('locale');
        });
    }

    /** The published translation of an entry in the given locale, if any. */
    private function translationOf(Entry $entry, string $locale): ?Entry
    {
        $group = $entry->translation_group;
        if (! is_string($group) || $group === '') {
            return null;
        }

        /** @var Entry|null $translation */
        $translation = Entry::type('page')
            ->where('translation_group', $group)
            ->where('locale', $locale)
            ->where('status', EntryStatus::Published->value)
            ->first();

        return $translation;
    }

    /** The configured custom 404 page, when one is set and published. */
    public function notFoundPage(PagesSettings $settings): ?Entry
    {
        if (! $this->schemaRegistry->has('page')) {
            return null;
        }

        return $this->publishedById($settings->not_found_page_id);
    }

    private function publishedById(?string $id): ?Entry
    {
        if ($id === null || $id === '') {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entry::type('page')
            ->whereKey($id)
            ->where('status', EntryStatus::Published->value)
            ->first();

        return $entry;
    }
}
