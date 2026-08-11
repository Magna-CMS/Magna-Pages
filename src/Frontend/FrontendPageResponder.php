<?php

declare(strict_types=1);

namespace Magna\Pages\Frontend;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Render\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves plugin frontend pages (ProvidesFrontendPages): matches the
 * requested path against the registry, enforces the page's declared auth
 * requirements BEFORE rendering, and wraps the plugin's view in the active
 * layout shell.
 *
 * Resolution order is the router's: a published page entry always wins
 * over a plugin page at the same path (the site owner outranks a plugin),
 * so this runs only after entry resolution misses.
 *
 * Plugin pages never enter the shared page cache — they are live plugin
 * output (chat, dashboards), and their auth gating is per-visitor.
 */
final class FrontendPageResponder
{
    public function __construct(
        private readonly FrontendPageRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly FrontendPageVisibility $visibility,
    ) {}

    /** The response for the path, or null when no plugin page claims it. */
    public function respond(string $path, Request $request): ?Response
    {
        $page = $this->registry->match($path);
        if ($page === null) {
            return null;
        }

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

        return response($this->renderer->renderFrontendPage($page), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Magna-Cache' => 'bypass',
        ]);
    }
}
