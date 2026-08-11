<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Pages\Themes\ThemeAuditor;
use Magna\Themes\ThemeManager;

/**
 * magna:theme:check v2 — the restricted-view audit (§C8) over one theme
 * or every installed one. Errors fail the command; the store's submission
 * gate runs the same audit.
 */
class ThemeCheckCommand extends Command
{
    protected $signature = 'magna:theme:check {theme? : vendor/name of one theme; omit to check all installed}';

    protected $description = 'Audit theme packages: manifest, tokens, and the restricted view rules';

    public function handle(ThemeAuditor $auditor, ThemeManager $themes): int
    {
        $name = $this->argument('theme');
        $names = is_string($name) && $name !== ''
            ? [$name]
            : array_keys($themes->installed());

        if ($names === []) {
            $this->info('No themes installed.');

            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($names as $themeName) {
            $findings = $auditor->audit($themeName);
            $this->line("<info>{$themeName}</info>".($findings === [] ? ' — clean' : ''));

            foreach ($findings as $finding) {
                $tag = $finding['level'] === 'error' ? '<error> error </error>' : '<comment> warn  </comment>';
                $this->line("  {$tag} {$finding['file']}: {$finding['message']}");
                if ($finding['level'] === 'error') {
                    $errors++;
                }
            }
        }

        if ($errors > 0) {
            $this->error("{$errors} error(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
