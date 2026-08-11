<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Blocks\BlockDefinition;
use Throwable;

/**
 * magna:block:check — validate a block.json before shipping it: the
 * definition parses, the handle and fields are well-formed, and the
 * one thing every author forgets is called out (a required field with
 * no default renders the block uninsertable-looking in older builders).
 */
class BlockCheckCommand extends Command
{
    protected $signature = 'magna:block:check {path : Path to a block.json file}';

    protected $description = 'Validate a block.json definition file';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        try {
            $definition = BlockDefinition::fromFile($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Block \"{$definition->handle}\" parses: {$definition->label} ({$definition->category}), "
            .count($definition->fields).' field(s).');

        $warnings = 0;
        foreach ($definition->fields as $field) {
            if ($field->required && $field->default === null) {
                $this->warn("Field \"{$field->handle}\" is required with no default — the builder inserts placeholder text; consider a default.");
                $warnings++;
            }
        }
        if ($definition->requiresPermission !== null) {
            $this->line("Inserting requires permission: {$definition->requiresPermission}.");
        }

        $viewHint = dirname($path, 2).'/resources/views/blocks/'.$definition->handle.'.blade.php';
        if (! is_file($viewHint)) {
            $this->warn("No view found at {$viewHint} — without one the block renders through theme/core views only.");
            $warnings++;
        }

        $this->line($warnings === 0 ? 'Clean.' : "{$warnings} warning(s).");

        return self::SUCCESS;
    }
}
