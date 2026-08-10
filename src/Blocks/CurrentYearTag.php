<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Magna\Blocks\DynamicTags\DynamicTag;

/**
 * The built-in `site.year` tag — the copyright-footer year that never goes
 * stale by hand. CACHE_STATIC: within a cached copy's bounded TTL the year
 * is constant for every visitor; the cache's own expiry covers the
 * new-year boundary.
 */
final class CurrentYearTag implements DynamicTag
{
    public function handle(): string
    {
        return 'site.year';
    }

    public function label(): string
    {
        return 'Current year';
    }

    public function cacheability(): string
    {
        return self::CACHE_STATIC;
    }

    public function resolve(): string
    {
        return now()->format('Y');
    }
}
