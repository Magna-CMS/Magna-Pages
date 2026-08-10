<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\Resolution\ResolvesBlockData;

/**
 * Resolves the Loop block: fetch from the chosen data source, clamp, and
 * hand the view a plain items list under the conventional keys.
 *
 * An unknown or vanished source resolves to zero items — a page whose
 * plugin was disabled renders a gap where the loop was, never an error
 * (the same degrade-don't-break stance as unknown block handles).
 */
final class LoopBlockResolver implements ResolvesBlockData
{
    private const MAX_ITEMS = 50;

    public function __construct(private readonly DataSourceRegistry $registry) {}

    public function handle(): string
    {
        return 'loop';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $handle = $data['source'] ?? null;
        $source = is_string($handle) ? $this->registry->get($handle) : null;

        if ($source === null) {
            return ['items' => []];
        }

        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 6;
        $limit = max(1, min(self::MAX_ITEMS, $limit));

        $items = $source->fetch(['limit' => $limit]);

        // The clamp is enforced HERE regardless of what the source returned
        // — a generous source must not turn one block into a data dump.
        return ['items' => array_slice(array_values(array_filter($items, 'is_array')), 0, $limit)];
    }
}
