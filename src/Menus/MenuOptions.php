<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Support\Facades\Schema;
use Magna\Blocks\Contracts\ProvidesOptions;

/**
 * Select options for the nav block's menu field: every defined menu,
 * keyed by handle.
 */
class MenuOptions implements ProvidesOptions
{
    /** @return array<string, string> */
    public function options(): array
    {
        if (! Schema::hasTable('pages_menus')) {
            return [];
        }

        /** @var array<string, string> */
        return Menu::query()
            ->orderBy('name')
            ->pluck('name', 'handle')
            ->all();
    }
}
