<?php

declare(strict_types=1);

namespace Magna\Pages\Listeners;

use Magna\Content\Entry;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Routing\RedirectManager;

/**
 * Watches page saves and writes an automatic 301 whenever a published
 * page's slug changes — old URLs keep working, search equity is preserved
 * (docs/magna-pages/05 §3).
 *
 * Registered as an Eloquent `updating` observer on the shared Entry model
 * (the only point where both the old and the new slug are visible); the
 * handler filters to the `page` type.
 */
class RecordSlugRenameRedirect
{
    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly PageCache $cache,
    ) {}

    public function handle(Entry $entry): void
    {
        if ($entry->getHandle() !== 'page') {
            return;
        }

        // The materialized path IS the URL: it changes on slug renames AND
        // on moves under a different parent — and the descendant cascade
        // saves each moved child through this same listener, so a whole
        // subtree rename records a 301 per moved URL.
        if (! $entry->isDirty('path')) {
            return;
        }

        $old = $entry->getOriginal('path');
        $new = $entry->getAttribute('path');

        if (! is_string($old) || ! is_string($new)) {
            return;
        }

        $locale = $entry->getOriginal('locale');

        $this->redirects->recordSlugChange($old, $new, is_string($locale) ? $locale : '');

        // A moved URL may be linked from anywhere (nav blocks, header
        // menus) — sitewide blast radius, full flush.
        $this->cache->flush();
    }
}
