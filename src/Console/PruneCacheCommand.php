<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Pages\Cache\PageCache;

/**
 * Housekeeping for the page cache: drop expired rows. Correctness never
 * depends on this (get() lazily discards expired entries and the TTL is a
 * backstop) — this keeps the table small. Schedule it from the host cron,
 * e.g. daily.
 */
class PruneCacheCommand extends Command
{
    protected $signature = 'magna:pages:cache-prune';

    protected $description = 'Delete expired entries from the Magna Pages page cache.';

    public function handle(PageCache $cache): int
    {
        $pruned = $cache->prune();

        $this->info("Pruned {$pruned} expired page-cache entr".($pruned === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
