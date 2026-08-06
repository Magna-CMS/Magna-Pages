<?php

declare(strict_types=1);

namespace Magna\Pages\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DB-backed HTML page cache with surrogate keys
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §B1).
 *
 * v1 invalidation contract (correctness first, precision grows with the
 * document reference index):
 *   - a page content edit purges `page:{id}` — exactly its cached URLs
 *   - slug renames, menu saves, and site-settings changes flush the site
 *     (their blast radius genuinely is every page: nav URLs, header menus,
 *     tokens) — the TTL is the backstop for anything unhooked yet
 *
 * Only successful guest responses without query strings are cached; per-user
 * content never enters the shared cache by construction.
 */
class PageCache
{
    /** Safety-net expiry even when no purge fires. */
    public const DEFAULT_TTL_SECONDS = 3600;

    public function get(string $url): ?string
    {
        $row = DB::table('pages_cache')
            ->where('url_hash', $this->hash($url))
            ->first();

        if ($row === null || ! is_string($row->body ?? null)) {
            return null;
        }

        if (is_string($row->expires_at ?? null) && now()->greaterThan($row->expires_at)) {
            DB::table('pages_cache')->where('id', $row->id)->delete();

            return null;
        }

        return $row->body;
    }

    /**
     * @param  list<string>  $surrogateKeys
     */
    public function put(string $url, string $body, array $surrogateKeys, ?int $ttlSeconds = null): void
    {
        $hash = $this->hash($url);

        DB::transaction(function () use ($url, $hash, $body, $surrogateKeys, $ttlSeconds): void {
            DB::table('pages_cache')->where('url_hash', $hash)->delete();

            $cacheId = (string) Str::ulid();
            DB::table('pages_cache')->insert([
                'id' => $cacheId,
                'url_hash' => $hash,
                'url' => $url,
                'body' => $body,
                'expires_at' => now()->addSeconds($ttlSeconds ?? self::DEFAULT_TTL_SECONDS),
                'created_at' => now(),
            ]);

            $rows = [];
            foreach (array_values(array_unique($surrogateKeys)) as $key) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'cache_id' => $cacheId,
                    'surrogate_key' => $key,
                ];
            }
            if ($rows !== []) {
                DB::table('pages_cache_keys')->insert($rows);
            }
        });
    }

    /**
     * Exact purge: delete every cached URL carrying any of the keys.
     *
     * @param  list<string>  $surrogateKeys
     */
    public function purge(array $surrogateKeys): void
    {
        if ($surrogateKeys === []) {
            return;
        }

        $cacheIds = DB::table('pages_cache_keys')
            ->whereIn('surrogate_key', $surrogateKeys)
            ->pluck('cache_id')
            ->unique();

        if ($cacheIds->isNotEmpty()) {
            DB::table('pages_cache')->whereIn('id', $cacheIds)->delete();
        }
    }

    /**
     * Full flush — reserved for changes whose blast radius really is every
     * page (menus, tokens, site settings), never the routine tool.
     */
    public function flush(): void
    {
        DB::table('pages_cache')->delete();
    }

    /** Drop expired rows (scheduled housekeeping). */
    public function prune(): int
    {
        return DB::table('pages_cache')->where('expires_at', '<', now())->delete();
    }

    private function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
