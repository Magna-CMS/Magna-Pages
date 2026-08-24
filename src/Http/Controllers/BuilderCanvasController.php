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

        /*
         * Chrome is edited IN ITS SLOT.
         *
         * This used to render a template part bare, reasoning that a shell
         * which also injected the published header would show two of them.
         * The cure was worse: with no part html the theme draws its OWN
         * header and the part's sections land in the main slot, so the
         * editor saw two headers, neither the one they were editing, and
         * nothing they typed appeared in a header at all.
         */
        $role = $entry->getHandle() === 'pages_template'
            ? $entry->getAttribute('role')
            : null;

        $html = $this->withBridge(
            is_string($role) && in_array($role, ['header', 'footer'], true)
                ? $this->renderer->renderChrome($entry, $role, builderMode: true)
                : $this->renderer->render(
                    $entry,
                    builderMode: true,
                    // A part with no chrome role (a popup, a referenced
                    // part) still edits bare: it has no slot to sit in.
                    withParts: $entry->getHandle() !== 'pages_template',
                ),
        );

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
     *
     * The stylesheet that travels with it gives EMPTY columns a hit area.
     * A column with nothing in it is zero pixels tall, so the builder's
     * drop-target search — which works from the rects this page reports —
     * could never find one, and "drag an element into the empty column you
     * just made" is the first thing anybody tries. It affects only the
     * editor: the published page never loads this.
     */
    private function withBridge(string $html): string
    {
        // Fingerprinted so the script can be cached hard: the canvas
        // reloads on every structural edit, and a revalidation round trip
        // per reload delays the handshake that selection and drag depend
        // on. The stamp changes when the file does, so an updated plugin
        // never serves a stale bridge.
        $source = $this->bridgePath();
        $stamp = is_file($source) ? (string) filemtime($source) : '0';

        $tag = '<style>'.self::CANVAS_CSS.'</style>'
            .'<script src="'.e(url('/pages-builder/bridge.js').'?v='.$stamp).'" defer></script>';

        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$tag
            : substr($html, 0, $position).$tag.substr($html, $position);
    }

    /** Editor-only affordances for nodes that have no size of their own. */
    private const CANVAS_CSS = <<<'CSS'
        [data-magna-kind="column"]:not(:has(> *)) {
            min-height: 72px;
            outline: 1px dashed rgba(61, 139, 253, 0.55);
            outline-offset: -4px;
        }
        /*
         * A block that renders nothing until it is given something — an
         * image with no picture, a download with no file. It gets a marker
         * node so it stays selectable; without a size that node is still
         * unclickable, which is the same problem one step later.
         *
         * aria-hidden on the element keeps it out of the accessibility
         * tree; this is scaffolding for a pointer, not content.
         */
        [data-magna-empty="true"] {
            display: block;
            min-height: 44px;
            border: 1px dashed rgba(61, 139, 253, 0.55);
            border-radius: 4px;
        }
        CSS;

    /** The bridge script itself. */
    public function bridge(): Response
    {
        Gate::authorize('pages.content');

        $path = $this->bridgePath();
        $script = is_file($path) ? (string) file_get_contents($path) : '';

        return response($script, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // Private: this is served behind an authorization check, so a
            // shared cache must not hold it. The canvas URL carries a
            // file-stamp, which is what makes a long max-age safe.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function bridgePath(): string
    {
        return dirname(__DIR__, 3).'/resources/js/builder-bridge.js';
    }
}
