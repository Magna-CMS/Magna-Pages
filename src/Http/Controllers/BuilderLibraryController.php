<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\DocumentIds;
use Magna\Pages\Library\LibraryClient;
use Magna\Pages\Render\PageRenderer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The builder's window onto the cloud library — a PROXY, never a browser
 * call to the hub (§C10): the panel origin talks only to its own site, the
 * site talks to the hub through the one hardened egress client, and hub
 * outages degrade to an empty panel instead of console errors.
 *
 * The proxy is also where hub data meets local truth: missing blocks are
 * computed HERE against this site's registry, because only this site knows
 * what is installed.
 */
final class BuilderLibraryController
{
    public function __construct(
        private readonly LibraryClient $library,
        private readonly BlockRegistry $blocks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pages.content');

        $assets = $this->library->assets(
            $request->string('kind')->value(),
            $request->string('q')->value(),
            $request->string('sort')->value(),
        );

        return response()->json([
            'assets' => array_map(fn (array $asset): array => [
                ...$asset,
                'missingBlocks' => $this->missingFrom($asset['requiredBlocks'] ?? null),
            ], $assets),
            'collections' => $this->library->collections(),
        ]);
    }

    public function collection(string $slug): JsonResponse
    {
        Gate::authorize('pages.content');

        $collection = $this->library->collection($slug);
        if ($collection === null) {
            throw new NotFoundHttpException('Library collection not found.');
        }

        $members = isset($collection['assets']) && is_array($collection['assets'])
            ? array_values(array_filter($collection['assets'], 'is_array'))
            : [];

        $collection['assets'] = array_map(fn (array $asset): array => [
            ...$asset,
            'missingBlocks' => $this->missingFrom($asset['requiredBlocks'] ?? null),
        ], $members);

        return response()->json($collection);
    }

    /** A fresh-id instance of a library asset, ready to insert as a patch. */
    public function instance(string $slug): JsonResponse
    {
        Gate::authorize('pages.content');

        $asset = $this->library->asset($slug);
        if ($asset === null) {
            throw new NotFoundHttpException('Library asset not found.');
        }

        // Stage 2: a paid asset without a licence is a purchase prompt,
        // not an error page.
        if (($asset['licenseRequired'] ?? false) === true) {
            return response()->json([
                'message' => 'This asset needs a licence. Buy the product on the marketplace, and it unlocks here.',
                'productSlug' => $asset['productSlug'] ?? null,
            ], 402);
        }

        /** @var array<mixed, mixed> $document */
        $document = $asset['document'];

        return response()->json([
            'kind' => $asset['kind'] ?? 'pattern',
            'name' => $asset['name'] ?? $slug,
            'missingBlocks' => $this->missingFrom($asset['requiredBlocks'] ?? null),
            'node' => DocumentIds::fresh($document),
        ]);
    }

    /**
     * Install a header or footer from the library as a template part.
     *
     * A header asset is an ordinary `part` that says which chrome it is,
     * so the hub needs no new kind and an older client seeing an unknown
     * role treats it as the generic part it already is. What differs is
     * only where it LANDS: pasting a header into the middle of a page is
     * never what anyone meant by importing one.
     *
     * Design-tier, because it creates site chrome rather than editing one
     * page — and it arrives unpublished, so installing a header never
     * changes what visitors see until someone chooses it.
     */
    public function installChrome(
        Request $request,
        PageTreeValidator $validator,
        PageTreeAuthorizer $authorizer,
        EntryManager $entries,
        string $slug,
    ): JsonResponse {
        Gate::authorize('pages.design');

        $asset = $this->library->asset($slug);
        if ($asset === null) {
            throw new NotFoundHttpException('Library asset not found.');
        }

        if (($asset['licenseRequired'] ?? false) === true) {
            return response()->json([
                'message' => 'This asset needs a licence. Buy the product on the marketplace, and it unlocks here.',
                'productSlug' => $asset['productSlug'] ?? null,
            ], 402);
        }

        $role = $asset['role'] ?? null;
        if (! is_string($role) || ! in_array($role, ['header', 'footer'], true)) {
            return response()->json(['message' => 'This asset is not a header or a footer.'], 422);
        }

        /** @var array<mixed, mixed> $raw */
        $raw = $asset['document'];
        $document = array_is_list($raw) ? $raw : [$raw];

        // The same walls the save path applies, before hub content becomes
        // an entry: a document nobody could have authored here must not
        // arrive by another door.
        $errors = [
            ...$validator->validate($document),
            ...$authorizer->authorize($document, $request->user()),
        ];
        if ($errors !== []) {
            return response()->json(['message' => implode(' ', $errors)], 422);
        }

        $name = is_string($asset['name'] ?? null) && $asset['name'] !== '' ? $asset['name'] : $slug;

        $entry = $entries->create('pages_template', [
            'title' => $name,
            'slug' => $this->freeSlug($slug),
            'kind' => 'part',
            'role' => $role,
            'blocks_data' => DocumentIds::fresh($document),
        ], $request->user()?->getAuthIdentifier());

        return response()->json([
            'id' => $entry->getKey(),
            'role' => $role,
            'title' => $name,
        ], 201);
    }

    /**
     * A slug nothing else is using.
     *
     * Two sites importing the same asset twice should end up with two
     * parts, not one overwritten one — and the slug space is shared with
     * the "header"/"footer" fallback names, which must not be claimed by
     * an import.
     */
    private function freeSlug(string $base): string
    {
        $base = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($base)) ?? '', '-');
        $base = $base === '' || in_array($base, ['header', 'footer'], true) ? 'library-'.$base : $base;

        $slug = $base;
        $suffix = 2;
        while (Entry::type('pages_template')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * The asset rendered through THIS site's renderer and theme, for the
     * library browser's preview pane.
     *
     * A live local render rather than a hub screenshot, because the honest
     * preview is what the asset will look like HERE — this theme, these
     * tokens — not on the hub's demo styling.
     *
     * The document is hub content and therefore untrusted input, and
     * preview renders it BEFORE the save path's walls have ever touched
     * it — so the same walls run first: validate, then authorize against
     * this actor. The browser adds the second wall by sandboxing the
     * iframe this responds into.
     */
    public function preview(Request $request, PageTreeValidator $validator, PageTreeAuthorizer $authorizer, PageRenderer $renderer, string $slug): Response
    {
        Gate::authorize('pages.content');

        $asset = $this->library->asset($slug);
        if ($asset === null) {
            throw new NotFoundHttpException('Library asset not found.');
        }

        // Same posture as instance(): a paid asset without a licence is a
        // purchase prompt, and its content stays unseen until it is owned.
        if (($asset['licenseRequired'] ?? false) === true) {
            return response('This asset needs a licence.', 402, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        /** @var array<mixed, mixed> $raw */
        $raw = $asset['document'];
        // A pattern or block ships one node; the renderer takes a document.
        $document = array_is_list($raw) ? $raw : [$raw];

        // A block-kind asset is a bare block node: wrap it in the section
        // scaffolding a document needs, purely for display.
        if (isset($raw['block'])) {
            $document = [[
                'id' => 'preview-section', 'type' => 'section', 'settings' => [],
                'columns' => [['id' => 'preview-column', 'span' => 12, 'settings' => [], 'blocks' => [$raw]]],
            ]];
        }

        $errors = [
            ...$validator->validate($document),
            ...$authorizer->authorize($document, $request->user()),
        ];
        if ($errors !== []) {
            return response(e(implode('
', $errors)), 422, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $html = $renderer->renderDocument($document, (string) ($asset['name'] ?? $slug), withParts: false);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Reflects a hub catalog that can change; never cache-share it.
            'Cache-Control' => 'no-store, must-revalidate',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    /**
     * Which of an asset's required blocks this site does not have.
     *
     * @return list<string>
     */
    private function missingFrom(mixed $required): array
    {
        if (! is_array($required)) {
            return [];
        }

        return array_values(array_filter(
            array_filter($required, 'is_string'),
            fn (string $handle): bool => ! $this->blocks->has($handle),
        ));
    }
}
