<?php

declare(strict_types=1);

namespace Magna\Pages\Cache;

use Magna\Content\Entry;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Pages\Blocks\LatestPagesSource;

/**
 * Cache invalidation for page lifecycle events. Content edits purge exactly
 * that page's cached URLs (`page:{id}`) and every cached listing of pages
 * (`pages:listing`); structural changes with sitewide blast radius (slug
 * renames — every nav link may point at the page) flush.
 *
 * Other plugins' content reaches the cache through core's
 * PublicContentChanged: purge by its tags, or flush when it says
 * everything changed.
 */
class PurgePageCache
{
    public function __construct(private readonly PageCache $cache) {}

    public function handleEntryEvent(object $event): void
    {
        $entry = property_exists($event, 'entry') ? $event->entry : null;
        if (! $entry instanceof Entry) {
            return;
        }

        // Template parts (headers, footers, reusable sections) render on
        // every page — their blast radius is the whole site.
        if ($entry->getHandle() === 'pages_template') {
            $this->cache->flush();

            return;
        }

        if ($entry->getHandle() !== 'page') {
            return;
        }

        $key = $entry->getKey();
        $this->cache->purge(is_string($key)
            ? ['page:'.$key, LatestPagesSource::CACHE_TAG]
            : [LatestPagesSource::CACHE_TAG]);
    }

    public function handlePublicContentChanged(PublicContentChanged $event): void
    {
        if ($event->everything) {
            $this->cache->flush();

            return;
        }

        $this->cache->purge(array_values($event->tags));
    }

    public function flushSite(): void
    {
        $this->cache->flush();
    }
}
