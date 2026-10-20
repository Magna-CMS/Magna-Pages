<?php

declare(strict_types=1);

namespace Magna\Pages\Listeners;

use Magna\Frontend\Events\PublicUrlsMoved;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Routing\RedirectManager;

/**
 * Turns another plugin's moved public URLs (a post slug renamed, a
 * permalink pattern edited) into redirects, so old links keep working.
 *
 * Moves are full site-relative paths, locale prefix included, so they are
 * recorded locale-less — the router tries the raw request path that way
 * first. RedirectManager applies the same chain-collapse and loop-guard
 * rules a page rename gets. A copy cached at the old address would keep
 * answering 200 instead of the redirect, so it is dropped.
 */
final class RecordMovedUrlRedirects
{
    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly PageCache $cache,
    ) {}

    public function handle(PublicUrlsMoved $event): void
    {
        foreach ($event->moves as $move) {
            if ($move->isNoop()) {
                continue;
            }

            $this->redirects->recordSlugChange($move->from, $move->to, '', $move->status);
            $this->cache->forget($move->from);
        }
    }
}
