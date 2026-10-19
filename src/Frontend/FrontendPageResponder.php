<?php

declare(strict_types=1);

namespace Magna\Pages\Frontend;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Frontend\FrontendPageResult;
use Magna\Pages\Render\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves plugin frontend pages (ProvidesFrontendPages): resolves the
 * requested path against the registry (exact paths first, then
 * parameterized ones like `blog/{slug}`), enforces the page's declared auth
 * requirements BEFORE anything runs, asks the page's data provider for its
 * payload, and wraps the plugin's view in the active layout shell.
 *
 * Resolution order is the router's: a published page entry always wins
 * over a plugin page at the same path (the site owner outranks a plugin),
 * so this runs only after entry resolution misses. A provider that finds
 * nothing for the captured parameters answers null, and the router carries
 * on to the redirect table and the themed 404 exactly as for a dead path.
 *
 * Plugin pages never enter the shared page cache — they are live plugin
 * output (chat, dashboards, provider-backed posts), and their auth gating
 * is per-visitor.
 */
final class FrontendPageResponder
{
    public function __construct(
        private readonly FrontendPageRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly FrontendPageVisibility $visibility,
        private readonly Container $container,
    ) {}

    /** The response for the path, or null when no plugin page claims it. */
    public function respond(string $path, Request $request): ?Response
    {
        $match = $this->registry->resolve($path);
        if ($match === null) {
            return null;
        }

        $page = $match->page;
        $user = $request->user();

        if ($user === null && ($page->requiresAuth || $page->permission !== null)) {
            // Send guests to the panel login and bring them back after.
            return Route::has('filament.magna.auth.login')
                ? redirect()->guest(route('filament.magna.auth.login'))
                : abort(403);
        }

        if (! $this->visibility->visibleTo($page, $user)) {
            abort(403);
        }

        $result = null;
        if ($page->controller !== null) {
            $result = $this->provide($page->name, $page->controller, $match->params);
            if ($result === null) {
                return null;
            }
        }

        return response($this->renderer->renderFrontendPage($page, $result), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Magna-Cache' => 'bypass',
        ]);
    }

    /**
     * The provider's answer for the captured parameters, or null when it
     * finds nothing — or when the declared class cannot serve as a provider,
     * which degrades the page to "not here" rather than a 500.
     *
     * @param  array<string, string>  $params
     */
    private function provide(string $pageName, string $controller, array $params): ?FrontendPageResult
    {
        try {
            $provider = $this->container->make($controller);
        } catch (BindingResolutionException $e) {
            Log::warning('Frontend page data provider could not be resolved.', [
                'page' => $pageName, 'controller' => $controller, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $provider instanceof FrontendPageDataProvider) {
            Log::warning('Frontend page controller is not a FrontendPageDataProvider.', [
                'page' => $pageName, 'controller' => $controller,
            ]);

            return null;
        }

        return $provider->resolve($params);
    }
}
