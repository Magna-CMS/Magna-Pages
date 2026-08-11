<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Pages\SiteKit\SiteKit;

/**
 * Show what applying a site-kit would change, without changing anything.
 */
class SiteDiffCommand extends Command
{
    protected $signature = 'magna:site:diff {path : Site-kit JSON file to compare against}';

    protected $description = 'Compare a site-kit bundle against this environment';

    public function handle(SiteKit $kit): int
    {
        $bundle = $this->readKit((string) $this->argument('path'));
        if ($bundle === null) {
            return self::FAILURE;
        }

        $diff = $kit->diff($bundle);

        foreach ($diff as $collection => $result) {
            $this->line("<info>{$collection}</info>");
            foreach (['added', 'changed', 'localOnly'] as $bucket) {
                foreach ($result[$bucket] as $key) {
                    $this->line(sprintf('  %-10s %s', $bucket, $key));
                }
            }
        }

        $total = array_sum(array_map(
            fn (array $r): int => count($r['added']) + count($r['changed']),
            $diff,
        ));
        $this->line($total === 0 ? 'Nothing to apply.' : "{$total} item(s) would be applied by magna:site:sync.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function readKit(string $path): ?array
    {
        $raw = @file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error("Could not read a site-kit from {$path}.");

            return null;
        }

        return $decoded;
    }
}
