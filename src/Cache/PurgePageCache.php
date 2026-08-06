<?php

declare(strict_types=1);

namespace Magna\Pages\Cache;

use Magna\Content\Entry;

/**
 * Cache invalidation for page lifecycle events. Content edits purge exactly
 * that page's cached URLs (`page:{id}`); structural changes with sitewide
 * blast radius (slug renames — every nav link may point at the page) flush.
 */
class PurgePageCache
{
    public function __construct(private readonly PageCache $cache) {}

    public function handleEntryEvent(object $event): void
    {
        $entry = property_exists($event, 'entry') ? $event->entry : null;
        if (! $entry instanceof Entry || $entry->getHandle() !== 'page') {
            return;
        }

        $key = $entry->getKey();
        if (is_string($key)) {
            $this->cache->purge(['page:'.$key]);
        }
    }

    public function flushSite(): void
    {
        $this->cache->flush();
    }
}
