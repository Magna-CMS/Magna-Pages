<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\PageRenderer;
use Magna\Pages\Routing\PageRouteResolver;
use Magna\Pages\Routing\RedirectManager;

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

        $entry = $this->resolver->resolve($request->path(), $settings);

        if ($entry === null) {
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

        if ($cacheable) {
            $entryKey = $entry->getKey();
            $this->cache->put($cacheUrl, $html, [
                'page:'.(is_string($entryKey) ? $entryKey : ''),
                'site:pages',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Magna-Cache' => $cacheable ? 'miss' : 'bypass',
        ]);
    }
}
