<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\PageRenderer;
use Magna\Pages\Routing\PageRouteResolver;

/**
 * Public page controller — the fallback route for every URL nothing else
 * claimed. Thin by rule: resolve, render, shape the response.
 */
final class PageController
{
    public function __construct(
        private readonly PageRouteResolver $resolver,
        private readonly PageRenderer $renderer,
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

        $entry = $this->resolver->resolve($request->path(), $settings);

        if ($entry === null) {
            $notFoundPage = $this->resolver->notFoundPage($settings);

            return response(
                $notFoundPage !== null
                    ? $this->renderer->render($notFoundPage)
                    : view('magna-pages::not-found')->render(),
                404,
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }

        return response(
            $this->renderer->render($entry),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
