<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Themes\ThemeManager;

/**
 * Scaffold a theme addon package: manifest with extends + pairsWith, an
 * empty block-views directory, and an additive tokens stub — the §5 addon
 * shape, ready for magna:theme:check.
 */
class ThemeAddonMakeCommand extends Command
{
    protected $signature = 'magna:theme-addon:make
        {name : vendor/name for the addon}
        {--extends=* : Host theme name, or * for any theme}
        {--pairs-with=* : Plugin(s) whose blocks this addon styles}';

    protected $description = 'Scaffold a theme addon (extends + pairsWith) in the themes directory';

    public function handle(ThemeManager $themes): int
    {
        $name = (string) $this->argument('name');
        if (preg_match('#^[a-z0-9]([a-z0-9_-]*[a-z0-9])?/[a-z0-9]([a-z0-9_-]*[a-z0-9])?$#', $name) !== 1) {
            $this->error('Name must be vendor/name.');

            return self::FAILURE;
        }

        $extends = array_values(array_filter((array) $this->option('extends'), 'is_string'));
        $pairsWith = array_values(array_filter((array) $this->option('pairs-with'), 'is_string'));
        if ($pairsWith === []) {
            $this->error('An addon needs at least one --pairs-with plugin — that is what it exists to style.');

            return self::FAILURE;
        }

        $base = $themes->pathFor($name);
        if (is_dir($base)) {
            $this->error("{$base} already exists.");

            return self::FAILURE;
        }

        mkdir($base.'/views/blocks', 0755, true);

        $manifest = [
            'name' => $name,
            'displayName' => ucwords(str_replace(['-', '_'], ' ', explode('/', $name)[1])),
            'description' => 'Styles '.implode(', ', $pairsWith).' blocks.',
            'version' => '0.1.0',
            'compat' => ['magna' => '^1.0'],
            'type' => 'magna-theme-addon',
            'extends' => $extends[0] ?? '*',
            'pairsWith' => $pairsWith,
        ];
        file_put_contents(
            $base.'/theme.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        // Additive tokens only — a key the host theme declares is ignored.
        file_put_contents($base.'/tokens.json', json_encode(['colors' => new \stdClass], JSON_PRETTY_PRINT).PHP_EOL);

        $this->info("Addon scaffolded at {$base}.");
        $this->line('Add views under views/blocks/ for the paired plugin\'s block handles, then run magna:theme:check '.$name.'.');

        return self::SUCCESS;
    }
}
