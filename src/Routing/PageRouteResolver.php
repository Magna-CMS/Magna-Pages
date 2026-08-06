<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Pages\PagesSettings;

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

    public function resolve(string $path, PagesSettings $settings): ?Entry
    {
        if (! $this->schemaRegistry->has('page')) {
            return null;
        }

        $path = trim($path, '/');

        if ($path === '') {
            return $this->publishedById($settings->home_page_id);
        }

        // Single-segment slugs only for now; nested paths resolve once the
        // page hierarchy (parent/materialized path) ships.
        if (str_contains($path, '/')) {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entry::type('page')
            ->where('slug', $path)
            ->where('status', EntryStatus::Published->value)
            ->first();

        return $entry;
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
