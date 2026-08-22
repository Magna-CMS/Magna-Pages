<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\BlockRegistry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\ApprovalManager;
use Magna\Pages\Builder\BuilderBootstrap;
use Magna\Pages\Builder\DocumentEditor;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Builder\FindsDocuments;
use Magna\Pages\Builder\LockManager;
use Magna\Pages\Builder\PageSettings;
use Magna\Users\User;
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
    use FindsDocuments;

    public function __construct(
        private readonly BuilderBootstrap $bootstrap,
        private readonly DocumentEditor $editor,
        private readonly BlockRegistry $blocks,
        private readonly LockManager $locks,
        private readonly ApprovalManager $approvals,
    ) {}

    /**
     * Everything the SPA needs to render a document on open, in one payload.
     * Opening also tries to take the edit lock — the common case is one
     * editor, and they should not need a second request to start typing.
     */
    public function bootstrap(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $entry = $this->findDocument($id);
        $user = $this->actor($request);
        $lock = $this->locks->acquire((string) $entry->getKey(), $user);

        $payload = $this->bootstrap->forEntry($entry, $request->user());
        $payload['lock'] = $this->lockPayload((string) $entry->getKey(), $user);

        // Whether a publish request is already waiting, so the top bar can
        // show "Requested" instead of offering the request again.
        $pending = $this->approvals->pendingFor((string) $entry->getKey());
        $payload['approval'] = $pending === null ? null : [
            'id' => $pending->id,
            'requested_at' => $pending->created_at?->toIso8601String(),
        ];

        return response()->json($payload);
    }

    /** Apply a batch of builder edits. Authorized per operation, all-or-nothing. */
    public function patch(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        // Writing requires holding the lock. 409 rather than 422: the batch
        // may be perfectly valid — somebody else simply owns the document
        // right now, and the response says who.
        $user = $this->actor($request);
        if (! $this->locks->holds($id, $user)) {
            return response()->json([
                'message' => 'Another editor holds this document.',
                'lock' => $this->lockPayload($id, $user),
            ], 409);
        }

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

        $entry = $this->findDocument($id);

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

    /**
     * Write the page's own settings — its background, its ground.
     *
     * Design-tier, not content: a page background restyles everything
     * drawn on it, which is the same kind of decision as a palette change
     * and a different one from editing a heading. It needs the lock like
     * any other write, because two editors disagreeing about a background
     * is exactly the collision the lock exists to prevent.
     *
     * Deliberately NOT part of the patch path: that path owns `blocks_data`
     * and blocks_data is a list of sections. A page-level key inside that
     * list would be a node that is not a node, and every walker in the
     * codebase would have to learn to skip it.
     */
    public function settings(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.design');

        $user = $this->actor($request);
        if (! $this->locks->holds($id, $user)) {
            return response()->json([
                'message' => 'Another editor holds this document.',
                'lock' => $this->lockPayload($id, $user),
            ], 409);
        }

        $request->validate(['settings' => ['present', 'array']]);

        $entry = $this->findDocument($id);
        $settings = PageSettings::sanitize((array) $request->input('settings', []));

        $entry->setAttribute('page_settings', $settings);
        $entry->save();

        return response()->json([
            'settings' => $settings,
            'updated_at' => $entry->fresh()?->updated_at?->toIso8601String(),
        ]);
    }

    /** The installed block catalog — what the Add panel can offer. */
    public function registry(): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json(['blocks' => $this->bootstrap->registryPayload($this->blocks)]);
    }

    /** Keep the lock alive. The SPA calls this on an interval while open. */
    public function heartbeat(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $user = $this->actor($request);
        $alive = $this->locks->heartbeat($id, $user);

        return response()->json(['held' => $alive, 'lock' => $this->lockPayload($id, $user)], $alive ? 200 : 409);
    }

    /**
     * Take the lock from its current holder. Any editor may — the common
     * case is a colleague's dead tab, and visibility (the holder is named,
     * the loser's next write is refused) is the protection, not a fight
     * over who ranks higher.
     */
    public function takeOver(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $entry = $this->findDocument($id);
        $user = $this->actor($request);
        $this->locks->takeOver((string) $entry->getKey(), $user);

        return response()->json(['lock' => $this->lockPayload($id, $user)]);
    }

    /**
     * Publish the page from the builder. Its own permission (a content
     * editor may draft all day without being allowed to ship), and its own
     * lock check — publishing what somebody else is mid-edit is the same
     * hazard as writing over them.
     */
    public function publish(Request $request, EntryManager $entries, string $id): JsonResponse
    {
        Gate::authorize('pages.publish');

        $user = $this->actor($request);
        if (! $this->locks->holds($id, $user)) {
            return response()->json([
                'message' => 'Another editor holds this document.',
                'lock' => $this->lockPayload($id, $user),
            ], 409);
        }

        $entry = $entries->publish($this->findDocument($id), actorId: (string) $user->getKey());

        return response()->json([
            'status' => $entry->status->value,
            'published_at' => $entry->published_at?->toIso8601String(),
            'url' => $entry->path !== null ? url('/'.$entry->path) : null,
        ]);
    }

    /** Give the lock up cleanly (the SPA calls this on close). */
    public function release(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $this->locks->release($id, $this->actor($request));

        return response()->json(['released' => true]);
    }

    /** @return array<string, mixed> */
    private function lockPayload(string $entryId, User $user): array
    {
        $lock = $this->locks->current($entryId);

        if ($lock === null) {
            return ['mine' => false, 'holder' => null];
        }

        $holder = User::query()->find($lock->user_id);

        return [
            'mine' => $lock->user_id === $user->getKey(),
            'holder' => [
                'id' => $lock->user_id,
                'name' => $holder?->name ?? 'Unknown user',
            ],
            'acquired_at' => $lock->acquired_at->toIso8601String(),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable behind the auth middleware; the type says so too.
            throw new NotFoundHttpException('No authenticated user.');
        }

        return $user;
    }
}
