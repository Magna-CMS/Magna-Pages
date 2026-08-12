<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\Request;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Collections\CollectionResponder;
use Magna\Pages\Frontend\FrontendPageResponder;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\Conditions\DocumentConditions;
use Magna\Pages\Render\PageRenderer;
use Magna\Pages\Routing\LocalePrefix;
use Magna\Pages\Routing\PageRouteResolver;
use Magna\Pages\Routing\RedirectManager;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public page controller — the fallback route for every URL nothing else
 * claimed. Thin by rule: resolve, render, shape the response.
 */
final class PageController
{
    public function __construct(
        private readonly PageRouteResolver $resolver,
        private readonly PageRenderer $renderer,
        private readonly RedirectManager $redirects,
        private readonly PageCache $cache,
        private readonly DocumentConditions $conditions,
        private readonly FrontendPageResponder $frontendPages,
        private readonly TemplatePartResolver $parts,
        private readonly CollectionResponder $collections,
        private readonly LocalePrefix $localePrefix,
        private readonly ThemeTokens $tokens,
    ) {}

    public function __invoke(Request $request): Response
    {
        $settings = PagesSettings::get();

        // Maintenance mode: guests get the holding page; authenticated
        // panel users keep browsing the real site (they are the ones
        // fixing it). Bypass tokens land with the token-architecture item.
        if ($settings->maintenance_mode && $request->user() === null) {
            return response(
                view('magna-pages::maintenance')->render(),
                503,
                ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '600'],
            );
        }

        // Shared page cache: guest GETs without query strings only — per-user
        // or parameterized responses never enter the shared cache.
        $cacheable = $request->user() === null && $request->query() === [];
        $cacheUrl = '/'.trim($request->path(), '/');

        if ($cacheable) {
            $hit = $this->cache->get($cacheUrl);
            if ($hit !== null) {
                return response($hit, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'X-Magna-Cache' => 'hit',
                ]);
            }
        }

        // §A2 locale-prefix strategy: /fr/about serves the French
        // translation and sets the app locale; bare paths serve the
        // fallback locale. Cache stays URL-keyed, so locales never mix.
        ['locale' => $locale, 'path' => $localizedPath] = $this->localePrefix->split($request->path());
        if ($locale !== null) {
            app()->setLocale($locale);
        }

        $entry = $this->resolver->resolve($localizedPath, $settings, $locale);

        if ($entry === null) {
            // Mounted collection (05-SITE-STRUCTURE §1): archive at the
            // prefix, single at prefix/{slug}. After real pages — an exact
            // page slug always wins — and before plugin pages.
            $collectionResponse = $this->collections->respond($request->path(), $request);
            if ($collectionResponse !== null) {
                return $collectionResponse;
            }

            // Plugin frontend page (§07-EXTENSIBILITY): after real pages —
            // the site owner's page outranks a plugin at the same path —
            // and before redirects, which only fire for dead paths.
            $pluginResponse = $this->frontendPages->respond($request->path(), $request);
            if ($pluginResponse !== null) {
                return $pluginResponse;
            }

            // A live page always wins over a redirect; redirects only fire
            // for paths nothing resolves anymore.
            $redirect = $this->redirects->forPath($request->path());
            if ($redirect !== null) {
                return response('', $redirect->status, [
                    'Location' => url('/'.ltrim($redirect->target_path, '/')),
                ]);
            }

            $notFoundPage = $this->resolver->notFoundPage($settings);

            return response(
                $notFoundPage !== null
                    ? $this->renderer->render($notFoundPage)
                    : view('magna-pages::not-found')->render(),
                404,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        $html = $this->renderer->render($entry);

        // §C9: the conditions in everything this URL renders get a veto on
        // caching — the page document AND every site-wide popup. A condition
        // we cannot evaluate makes the page uncacheable outright; a schedule
        // shortens the cached copy's life to its next boundary, so the cache
        // never serves yesterday's banner past its `until`.
        if ($cacheable) {
            $document = $entry->getAttribute('blocks_data');
            $verdict = $this->conditions->cacheVerdictAll(
                [
                    is_array($document) ? $document : [],
                    ...array_column($this->parts->popups(), 'document'),
                ],
                $request->user(),
                now(),
            );
            $cacheable = $verdict['cacheable'];

            // A scheduled design change is another expiry boundary: the
            // earliest of the two decides how long this copy may live.
            $expiresAt = $verdict['expiresAt'];
            $styleChange = $this->tokens->nextScheduledChange();
            if ($styleChange !== null && ($expiresAt === null || $styleChange->lessThan($expiresAt))) {
                $expiresAt = $styleChange;
            }

            if ($cacheable) {
                $ttl = $expiresAt === null
                    ? null
                    : max(1, min(PageCache::DEFAULT_TTL_SECONDS, (int) now()->diffInSeconds($expiresAt, false)));

                $entryKey = $entry->getKey();
                $this->cache->put($cacheUrl, $html, [
                    'page:'.(is_string($entryKey) ? $entryKey : ''),
                    'site:pages',
                ], $ttl);
            }
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Magna-Cache' => $cacheable ? 'miss' : 'bypass',
        ]);
    }
}
