<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

use Illuminate\Support\Facades\DB;

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
    /**
     * Record an automatic 301 after a page slug rename.
     */
    public function recordSlugChange(string $oldPath, string $newPath, string $locale = ''): void
    {
        $oldPath = trim($oldPath, '/');
        $newPath = trim($newPath, '/');

        if ($oldPath === '' || $newPath === '' || $oldPath === $newPath) {
            return;
        }

        DB::transaction(function () use ($oldPath, $newPath, $locale): void {
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
                ['target_path' => $newPath, 'status' => 301, 'automatic' => true],
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
}
