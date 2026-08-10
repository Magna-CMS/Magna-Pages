<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Pages\Builder\FindsDocuments;
use Magna\Pages\Render\FragmentRenderer;
use Magna\Pages\Render\PageRenderer;

/**
 * What the builder's canvas iframe loads, and how it refreshes one node.
 *
 * Both go through the production renderer in builder mode: same views, same
 * resolve seam, same theme. The canvas is the published page with markers,
 * not a preview of it.
 *
 * The fragment endpoint accepts UNSAVED document state, so it validates and
 * authorizes that state exactly as a save would — a hand-posted document
 * cannot render a block the actor could not have inserted.
 */
final class BuilderCanvasController
{
    use FindsDocuments;

    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly FragmentRenderer $fragments,
        private readonly PageTreeValidator $validator,
        private readonly PageTreeAuthorizer $authorizer,
    ) {}

    /** The full page, rendered into the canvas iframe. */
    public function canvas(Request $request, string $id): Response
    {
        Gate::authorize('pages.content');

        $entry = $this->findDocument($id);

        // A template part edits BARE — rendering the header part inside a
        // shell that also injects the published header would show the
        // editor two headers, one of them stale.
        $html = $this->withBridge($this->renderer->render(
            $entry,
            builderMode: true,
            withParts: $entry->getHandle() !== 'pages_template',
        ));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Canvas HTML reflects in-flight editor state; caching it would
            // serve a stale document to the next editing session.
            'Cache-Control' => 'no-store, must-revalidate',
            // The whole point of this response is to be framed by the
            // builder — same origin only. SecurityHeadersMiddleware
            // respects an explicit policy instead of appending DENY.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    /** One node's HTML, for the canvas's morph-swap loop. */
    public function fragment(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $request->validate([
            'node' => ['required', 'string'],
            'document' => ['required', 'array'],
        ]);

        $entry = $this->findDocument($id);

        /** @var array<mixed, mixed> $document */
        $document = (array) $request->input('document', []);

        $errors = [
            ...$this->validator->validate($document),
            ...$this->authorizer->authorize($document, $request->user()),
        ];
        if ($errors !== []) {
            return response()->json(['message' => $errors[0]], 422);
        }

        $node = $request->string('node')->value();
        $html = $this->fragments->renderBlock($document, $node, context: $entry);

        if ($html === null) {
            return response()->json(['message' => 'That node is not in the document.'], 404);
        }

        return response()->json(['node' => $node, 'html' => $html]);
    }

    /**
     * Add the canvas bridge to a rendered page.
     *
     * Appended here rather than emitted by the layout so themes stay
     * unaware of the builder entirely — a theme cannot forget to include
     * it, and cannot include it on the public site by mistake. Loaded as a
     * separate file, not inlined, so it needs no CSP script-src exception.
     */
    private function withBridge(string $html): string
    {
        $tag = '<script src="'.e(url('/pages-builder/bridge.js')).'" defer></script>';

        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$tag
            : substr($html, 0, $position).$tag.substr($html, $position);
    }

    /** The bridge script itself. */
    public function bridge(): Response
    {
        Gate::authorize('pages.content');

        $path = dirname(__DIR__, 3).'/resources/js/builder-bridge.js';
        $script = is_file($path) ? (string) file_get_contents($path) : '';

        return response($script, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
