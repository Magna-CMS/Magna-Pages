<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Magna\Blocks\Contracts\ProvidesOptions;
use Magna\Blocks\DataSources\DataSourceRegistry;

/**
 * The Loop block's source picker: every data source enabled plugins have
 * registered, by label.
 */
final class DataSourceOptions implements ProvidesOptions
{
    public function __construct(private readonly DataSourceRegistry $registry) {}

    /** @return array<string, string> */
    public function options(): array
    {
        $options = [];
        foreach ($this->registry->all() as $handle => $source) {
            $options[$handle] = $source->label();
        }
        ksort($options);

        return $options;
    }
}
