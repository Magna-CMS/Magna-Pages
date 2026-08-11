<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Pages\SiteKit\SiteKit;

/**
 * Write the site as a site-kit bundle. The environment-promotion pair is
 * magna:site:diff (what would change) + magna:site:sync (apply).
 */
class SiteExportCommand extends Command
{
    protected $signature = 'magna:site:export {path : File to write the site-kit JSON to}';

    protected $description = 'Export pages, templates, menus, and site settings as a site-kit bundle';

    public function handle(SiteKit $kit): int
    {
        $path = (string) $this->argument('path');

        $json = json_encode($kit->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->info("Site kit written to {$path}.");

        return self::SUCCESS;
    }
}
