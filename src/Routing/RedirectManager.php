<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Illuminate\Support\Facades\DB;
use Magna\Frontend\LocaleUrls;

/**
 * Maintains the redirect table with the two invariants naive slug-rename
 * implementations get wrong (and WordPress redirect plugins patched for
 * years — chains and loops):
 *
 *  - CHAIN COLLAPSE: renaming a → b then b → c must leave a → c directly,
 *    never a → b → c (multi-hop redirects burn crawl budget and latency).
 *  - LOOP GUARD: renaming a → b and then back b → a must delete the
 *    now-inverted a → b row instead of creating a → b → a.
 */
class RedirectManager
{
    public function __construct(private readonly LocaleUrls $localeUrls) {}

    /**
     * Record an automatic redirect after a page slug rename (or any URL
     * move another plugin reports — those carry their own status).
     */
    public function recordSlugChange(string $oldPath, string $newPath, string $locale = '', int $status = 301): void
    {
        $oldPath = trim($oldPath, '/');
        $newPath = trim($newPath, '/');

        if ($oldPath === '' || $newPath === '' || $oldPath === $newPath) {
            return;
        }

        DB::transaction(function () use ($oldPath, $newPath, $locale, $status): void {
            // Loop guard: anything redirecting FROM the new path is now a
            // live page again — remove it or every visit bounces forever.
            PageRedirect::query()
                ->where('source_path', $newPath)
                ->where('locale', $locale)
                ->delete();

            // Chain collapse: rows already pointing AT the old path follow
            // it to the new destination in one hop.
            PageRedirect::query()
                ->where('target_path', $oldPath)
                ->where('locale', $locale)
                ->update(['target_path' => $newPath]);

            PageRedirect::query()->updateOrCreate(
                ['source_path' => $oldPath, 'locale' => $locale],
                ['target_path' => $newPath, 'status' => $status, 'automatic' => true],
            );
        });
    }

    /**
     * The redirect for a request path, if one exists.
     */
    public function forPath(string $path, string $locale = ''): ?PageRedirect
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        return PageRedirect::query()
            ->where('source_path', $path)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Where a request should be redirected, if anywhere.
     *
     * Two kinds of row live in the table. A locale-less row recorded for a
     * full site-relative path (a plugin's moved URL: `fr/blog/old`) matches
     * the raw request path and is tried first. A page rename is recorded
     * under the page's locale with the prefix already stripped, so it only
     * matches the localized path — in the request's locale, then
     * locale-less, which applies under every prefix. The Location keeps the
     * request's prefix for those.
     *
     * @param  string  $locale  the locale being served (the fallback locale for a bare path)
     */
    public function resolve(string $rawPath, string $localizedPath, string $locale): ?RedirectTarget
    {
        $raw = $this->forPath($rawPath);
        if ($raw !== null) {
            return new RedirectTarget($raw->status, $this->localeUrls->urlFor($this->localeUrls->fallback(), $raw->target_path));
        }

        // A bare path IS its localized path; its locale-less row was the raw try.
        $candidates = trim($rawPath, '/') === trim($localizedPath, '/') ? [$locale] : [$locale, ''];
        foreach (array_unique($candidates) as $candidate) {
            $redirect = $this->forPath($localizedPath, $candidate);
            if ($redirect !== null) {
                return new RedirectTarget($redirect->status, $this->localeUrls->urlFor($locale, $redirect->target_path));
            }
        }

        return null;
    }
}
