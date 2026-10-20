<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Illuminate\Contracts\Container\Container;
use Magna\Blocks\DataSources\DataSourcePage;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DataSources\DeclaresCacheability;
use Magna\Blocks\DataSources\PaginatedDataSource;
use Magna\Blocks\Resolution\ResolverBudget;
use Magna\Blocks\Resolution\ResolvesBlockData;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\Pagination;
use Magna\Frontend\RenderContext;

/**
 * Resolves the Loop block: fetch from the chosen data source, clamp, and
 * hand the view a plain items list under the conventional keys — plus,
 * when the block paginates over a PaginatedDataSource, this request's
 * Magna\Frontend\Pagination under `pagination` (null otherwise).
 *
 * An unknown or vanished source resolves to zero items — a page whose
 * plugin was disabled renders a gap where the loop was, never an error
 * (the same degrade-don't-break stance as unknown block handles).
 *
 * The request's RenderContext and CacheCollector are scoped per request,
 * so they are resolved per call: this resolver is registered once at boot
 * and may outlive the request it was built in.
 */
final class LoopBlockResolver implements ResolvesBlockData
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly DataSourceRegistry $registry,
        private readonly ResolverBudget $budget,
        private readonly Container $container,
    ) {}

    public function handle(): string
    {
        return 'loop';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{items: list<array<mixed>>, pagination: Pagination|null}
     */
    public function resolve(array $data): array
    {
        $handle = $data['source'] ?? null;
        $source = is_string($handle) ? $this->registry->get($handle) : null;

        if ($source === null) {
            return ['items' => [], 'pagination' => null];
        }

        $limit = is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 6;
        $limit = max(1, min(self::MAX_ITEMS, $limit));

        $context = $this->container->make(RenderContext::class);
        $config = ['limit' => $limit, 'locale' => $context->locale()];

        // What the source says about its own freshness rides along on the
        // cached page: purge tags, and when it next changes unprompted.
        if ($source instanceof DeclaresCacheability) {
            $collector = $this->container->make(CacheCollector::class);
            $collector->tag(...$source->cacheTags($config));
            $collector->expireAt($source->nextChangeAt($config));
        }

        $paginate = ($data['pagination'] ?? 'none') === 'pages' && $source instanceof PaginatedDataSource;

        /*
         * Through the render budget: a data source is plugin code fetching
         * on a visitor's request, and a page with several slow loops is the
         * shape this bounds. A refused fetch renders the same empty loop a
         * vanished source does — and paginates nothing.
         */
        $fetched = $this->budget->spend(
            'source',
            $source->handle(),
            fn (): mixed => $paginate && $source instanceof PaginatedDataSource
                ? $source->fetchPage($config, $context->page(), $limit)
                : $source->fetch($config),
        );

        $pagination = null;
        if ($fetched instanceof DataSourcePage) {
            $pagination = $context->paginate($fetched->total, $limit);
            $fetched = $fetched->items;
        }

        $items = is_array($fetched) ? array_values(array_filter($fetched, 'is_array')) : [];

        // The clamp is enforced HERE regardless of what the source returned
        // — a generous source must not turn one block into a data dump.
        return ['items' => array_slice($items, 0, $limit), 'pagination' => $pagination];
    }
}
