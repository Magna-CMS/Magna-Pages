<?php

declare(strict_types=1);

namespace Magna\Pages\Cache;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Magna\Blocks\Resolution\ResolverBudget;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\RenderContext;
use Magna\Pages\Render\Conditions\DocumentConditions;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeTokens;

/**
 * Decides whether a public response enters the shared page cache, and
 * stores it — for page entries and plugin frontend pages alike.
 *
 * The key is the request's path plus, at most, `?page=2..100`: any other
 * query string, a signed-in visitor, or a session carrying flash data (a
 * form's "sent" message, validation errors — meant for this visitor's next
 * request only) never reads or writes the cache.
 *
 * After rendering, everything that drew the response gets a say: §C9
 * conditions in the documents and site-wide popups, a spent resolver
 * budget, a veto or expiry the CacheCollector gathered, a scheduled design
 * change, and whether a `?page=N` request actually paginated.
 */
final class PageCacheWriter
{
    public function __construct(
        private readonly PageCache $cache,
        private readonly DocumentConditions $conditions,
        private readonly TemplatePartResolver $parts,
        private readonly ThemeTokens $tokens,
        private readonly ResolverBudget $budget,
        private readonly Container $container,
    ) {}

    /** The cache key for this request, or null when its response is never shared. */
    public function keyFor(Request $request): ?string
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->user() !== null
            || $this->carriesFlash($request)
        ) {
            return null;
        }

        $path = '/'.trim($request->path(), '/');
        $query = $request->query();
        if ($query === []) {
            return $path;
        }

        $page = $query['page'] ?? null;
        if (count($query) === 1
            && is_string($page)
            && preg_match('/^[1-9][0-9]{0,2}$/', $page) === 1
            && (int) $page >= 2
            && (int) $page <= RenderContext::MAX_PAGE
        ) {
            return $path.'?page='.$page;
        }

        return null;
    }

    /**
     * Store a rendered response under $key unless anything that drew it
     * objects. Returns whether it was stored.
     *
     * @param  list<string>  $tags  Purge tags for this response, besides what the collector gathered.
     * @param  list<array<mixed, mixed>>  $documents  Block documents the response rendered (popups are added here).
     */
    public function store(string $key, string $html, array $tags, array $documents = [], ?DateTimeInterface $expiresAt = null): bool
    {
        $context = $this->container->make(RenderContext::class);
        $collector = $this->container->make(CacheCollector::class);

        // A ?page=N nothing paginated is a duplicate of page 1; past the
        // end is a 404, never a page of its own.
        if ($context->outOfRange() || ($context->page() > 1 && ! $context->paginated())) {
            return false;
        }

        /*
         * A render that spent its resolver budget produced gaps where slow
         * tags and loops should have been. Storing that copy would outlive
         * the slow minute that caused it.
         */
        if ($collector->vetoed() || $this->budget->exhausted()) {
            return false;
        }

        // §C9: a condition we cannot evaluate makes the response uncacheable;
        // a schedule shortens the copy's life to its next boundary.
        $verdict = $this->conditions->cacheVerdictAll(
            [...$documents, ...array_column($this->parts->popups(), 'document')],
            null,
            now(),
        );
        if (! $verdict['cacheable']) {
            return false;
        }

        $expiry = $this->earliest([
            $verdict['expiresAt'],
            $this->tokens->nextScheduledChange(),
            $collector->expiresAt(),
            $expiresAt,
        ]);
        $ttl = $expiry === null
            ? null
            : max(1, min(PageCache::DEFAULT_TTL_SECONDS, (int) now()->diffInSeconds($expiry, false)));

        $this->cache->put($key, $html, array_values(array_unique([...$tags, ...$collector->tags()])), $ttl);

        return true;
    }

    private function carriesFlash(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return $session->get('_flash.old', []) !== [] || $session->get('_flash.new', []) !== [];
    }

    /** @param  list<DateTimeInterface|null>  $moments */
    private function earliest(array $moments): ?CarbonImmutable
    {
        $earliest = null;
        foreach ($moments as $moment) {
            if ($moment === null) {
                continue;
            }

            $moment = CarbonImmutable::instance($moment);
            if ($earliest === null || $moment->lessThan($earliest)) {
                $earliest = $moment;
            }
        }

        return $earliest;
    }
}
