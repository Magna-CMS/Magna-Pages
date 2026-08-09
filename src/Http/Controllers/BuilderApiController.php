<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Entry;
use Magna\Pages\Builder\BuilderBootstrap;
use Magna\Pages\Builder\DocumentEditor;
use Magna\Pages\Builder\Exceptions\PatchException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The builder's management API: what the SPA loads on open, and the one
 * endpoint it writes through.
 *
 * Rides the panel session (same origin as the admin) rather than a bearer
 * token — the builder is an admin surface, so it gets CSRF and the session
 * guard for free instead of minting a second credential that would then need
 * its own revocation story.
 */
final class BuilderApiController
{
    public function __construct(
        private readonly BuilderBootstrap $bootstrap,
        private readonly DocumentEditor $editor,
        private readonly BlockRegistry $blocks,
    ) {}

    /** Everything the SPA needs to render a document on open, in one payload. */
    public function bootstrap(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json($this->bootstrap->forEntry($this->findPage($id), $request->user()));
    }

    /** Apply a batch of builder edits. Authorized per operation, all-or-nothing. */
    public function patch(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $request->validate([
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.op' => ['required', 'string'],
            'operations.*.path' => ['required', 'string'],
            'operations.*.from' => ['sometimes', 'string'],
        ]);

        // Read the operations from the raw input, not the validated array:
        // validate() returns only the keys it was given rules for, and an
        // operation's `value` is deliberately schemaless (any block field
        // type can appear there) — validating it away would silently turn
        // every edit into a write of null.
        /** @var list<array<mixed, mixed>> $operations */
        $operations = array_values(array_filter(
            (array) $request->input('operations', []),
            'is_array',
        ));

        $entry = $this->findPage($id);

        try {
            $document = $this->editor->applyPatch($entry, $operations, $request->user());
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'document' => $document,
            'updated_at' => $entry->fresh()?->updated_at?->toIso8601String(),
        ]);
    }

    /** The installed block catalog — what the Add panel can offer. */
    public function registry(): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json(['blocks' => $this->bootstrap->registryPayload($this->blocks)]);
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
