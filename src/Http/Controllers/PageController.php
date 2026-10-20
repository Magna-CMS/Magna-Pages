<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\LocaleUrls;
use Magna\Frontend\RenderContext;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Cache\PageCacheWriter;
use Magna\Pages\Collections\CollectionResponder;
use Magna\Pages\Frontend\FrontendPageResponder;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\PageRenderer;
use Magna\Pages\Routing\PageRouteResolver;
use Magna\Pages\Routing\RedirectManager;
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
        private readonly PageCacheWriter $cacheWriter,
        private readonly FrontendPageResponder $frontendPages,
        private readonly CollectionResponder $collections,
        private readonly LocaleUrls $localeUrls,
        private readonly Container $container,
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

        // Shared page cache: guest GET/HEAD, no flash data, no query string
        // but ?page=N — PageCacheWriter owns the rule.
        $cacheKey = $this->cacheWriter->keyFor($request);
        if ($cacheKey !== null) {
            $hit = $this->cache->get($cacheKey);
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
        ['locale' => $locale, 'path' => $localizedPath] = $this->localeUrls->split($request->path());
        if ($locale !== null) {
            app()->setLocale($locale);
        }
        $context = $this->startRender($request, $locale);

        $entry = $this->resolver->resolve($localizedPath, $settings, $locale);

        if ($entry === null) {
            // Mounted collection (05-SITE-STRUCTURE §1): archive at the
            // prefix, single at prefix/{slug}. After real pages — an exact
            // page slug always wins — and before plugin pages.
            $response = $this->collections->respond($request->path(), $request)
                // Plugin frontend page (§07-EXTENSIBILITY): after real pages
                // — the site owner's page outranks a plugin at the same path
                // — and before redirects, which only fire for dead paths.
                ?? $this->frontendPages->respond($localizedPath, $request, $cacheKey);
            if ($response !== null) {
                return $context->outOfRange() ? $this->notFound($settings) : $response;
            }

            // A live page always wins over a redirect; redirects only fire
            // for paths nothing resolves anymore.
            $redirect = $this->redirects->resolve($request->path(), $localizedPath, $this->localeUrls->effective($locale));
            if ($redirect !== null) {
                return response('', $redirect->status, ['Location' => url($redirect->path)]);
            }

            return $this->notFound($settings);
        }

        $html = $this->renderer->render($entry);

        if ($context->outOfRange()) {
            return $this->notFound($settings);
        }

        $document = $entry->getAttribute('blocks_data');
        $entryKey = $entry->getKey();
        $stored = $cacheKey !== null && $this->cacheWriter->store(
            $cacheKey,
            $html,
            ['page:'.(is_string($entryKey) ? $entryKey : ''), 'site:pages'],
            [is_array($document) ? $document : []],
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Magna-Cache' => $stored ? 'miss' : 'bypass',
        ]);
    }

    /**
     * Fresh per-request render state. Both objects are scoped, but a
     * container that serves several requests without a scope flush (the
     * test client, a sub-request) would otherwise carry one response's page
     * flags, tags or cache veto into the next.
     */
    private function startRender(Request $request, ?string $locale): RenderContext
    {
        $this->container->forgetInstance(RenderContext::class);
        $this->container->forgetInstance(CacheCollector::class);

        $context = $this->container->make(RenderContext::class);
        $context->setRequest($request, $locale);

        return $context;
    }

    private function notFound(PagesSettings $settings): Response
    {
        $notFoundPage = $this->resolver->notFoundPage($settings);

        return response(
            $notFoundPage !== null
                ? $this->renderer->render($notFoundPage)
                : view('magna-pages::not-found')->render(),
            404,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
