<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Pages\Render\PageRenderer;

/**
 * Themed live preview for the block editor: renders POSTed (unsaved)
 * document state through the exact production pipeline — active theme,
 * tokens, resolve step — so the preview cannot drift from the published
 * result. The rendering backend behind core's ProvidesDocumentPreview
 * contract binding.
 */
final class PagePreviewController
{
    public function __construct(
        private readonly PageTreeValidator $validator,
        private readonly PageTreeAuthorizer $authorizer,
        private readonly PageRenderer $renderer,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('blocks.preview');

        $json = $request->input('blocks_data', '[]');
        $decoded = json_decode(is_string($json) ? $json : '[]', true);
        $document = is_array($decoded) ? $decoded : [];

        $errors = [
            ...$this->validator->validate($document),
            ...$this->authorizer->authorize($document, $request->user()),
        ];
        if ($errors !== []) {
            return response(e(implode("\n", $errors)), 422, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $title = $request->input('title', '');

        $html = $this->renderer->renderDocument($document, is_string($title) ? $title : '');

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
