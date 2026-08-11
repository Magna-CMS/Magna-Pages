<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\SiteKit\SiteKit;

/**
 * Apply a site-kit bundle to this environment: upsert pages, templates,
 * menus, and settings in one transaction. Rows that exist locally but not
 * in the kit are LEFT ALONE — deletion deserves its own explicit step,
 * never a promotion side effect.
 */
class SiteSyncCommand extends Command
{
    protected $signature = 'magna:site:sync {path : Site-kit JSON file to apply}';

    protected $description = 'Apply a site-kit bundle to this environment (upserts only)';

    public function handle(SiteKit $kit): int
    {
        $raw = @file_get_contents((string) $this->argument('path'));
        $bundle = $raw === false ? null : json_decode($raw, true);
        if (! is_array($bundle)) {
            $this->error('Could not read a site-kit from that path.');

            return self::FAILURE;
        }

        try {
            $counts = $kit->apply($bundle);
        } catch (\Throwable $e) {
            $this->error('Nothing applied: '.$e->getMessage());

            return self::FAILURE;
        }

        // The whole site may have changed shape.
        app(PageCache::class)->flush();

        $this->info(sprintf(
            'Applied: %d created, %d updated, %d menu(s) synced.',
            $counts['created'],
            $counts['updated'],
            $counts['menus'],
        ));

        return self::SUCCESS;
    }
}
