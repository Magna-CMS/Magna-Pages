<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\Entry;
use Magna\Pages\Render\FragmentRenderer;
use Magna\Pages\Render\PageRenderer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $html = $this->renderer->render($this->findPage($id), builderMode: true);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Canvas HTML reflects in-flight editor state; caching it would
            // serve a stale document to the next editing session.
            'Cache-Control' => 'no-store, must-revalidate',
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

        $this->findPage($id);

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
        $html = $this->fragments->renderBlock($document, $node);

        if ($html === null) {
            return response()->json(['message' => 'That node is not in the document.'], 404);
        }

        return response()->json(['node' => $node, 'html' => $html]);
    }

    private function findPage(string $id): Entry
    {
        $entry = Entry::type('page')->find($id);

        if ($entry === null) {
            throw new NotFoundHttpException('Page not found.');
        }

        return $entry;
    }
}
