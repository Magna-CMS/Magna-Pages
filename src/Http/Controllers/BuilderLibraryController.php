<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\BlockRegistry;
use Magna\Pages\Builder\DocumentIds;
use Magna\Pages\Library\LibraryClient;
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
