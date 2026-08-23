<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Content\Entry;
use Magna\Content\EntryManager;

/**
 * Deletes the pages the Playwright suite leaves behind.
 *
 * The E2E suite runs against a LIVE install by design — the point of the
 * one-renderer architecture is that the browser sees production behaviour,
 * so the tests must too. The cost is that every run creates real pages in
 * a real database, and they accumulate: a development install reached 428
 * of them, which pushed the templates section off the bottom of the Pages
 * screen and made designing a header look impossible.
 *
 * Refuses in production outright, like the account command it sits beside.
 * A command whose job is to delete content in bulk has no business running
 * anywhere real, however careful its pattern is.
 */
class PruneFixturesCommand extends Command
{
    protected $signature = 'magna:pages:prune-fixtures
        {--dry-run : List what would be deleted without deleting it}
        {--quiet-unless-found : Say nothing when there is nothing to prune}';

    protected $description = 'Delete the pages left behind by the Playwright end-to-end suite.';

    /**
     * A slug ending in a 13-digit millisecond timestamp.
     *
     * Every fixture the suite creates is named that way, and nothing a
     * person would name a page looks like it. Narrow on purpose: the cost
     * of missing one is a stray row, and the cost of matching too much is
     * somebody's content.
     */
    private const FIXTURE_SLUG = '/-1[0-9]{12}$/';

    public function handle(EntryManager $entries): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to bulk-delete content in production.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $quiet = (bool) $this->option('quiet-unless-found');

        $fixtures = [];
        foreach (['page', 'pages_template'] as $type) {
            foreach (Entry::type($type)->get() as $entry) {
                if (preg_match(self::FIXTURE_SLUG, (string) $entry->getAttribute('slug')) === 1) {
                    $fixtures[] = $entry;
                }
            }
        }

        if ($fixtures === []) {
            if (! $quiet) {
                $this->info('Nothing to prune.');
            }

            return self::SUCCESS;
        }

        if ($dryRun) {
            foreach ($fixtures as $entry) {
                $this->line('  would delete: '.$entry->getAttribute('slug'));
            }
            $this->info(count($fixtures).' fixture(s) would be deleted.');

            return self::SUCCESS;
        }

        foreach ($fixtures as $entry) {
            // Through the manager, so revisions, cache keys and locks go
            // with it rather than being orphaned rows nobody looks at.
            $entries->delete($entry);
        }

        $this->info('Pruned '.count($fixtures).' end-to-end fixture(s).');

        return self::SUCCESS;
    }
}
