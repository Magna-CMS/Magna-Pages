<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Content\EntryManager;
use Magna\Content\Models\Revision;
use Magna\Pages\Builder\FindsDocuments;
use Magna\Pages\Builder\LockManager;
use Magna\Pages\Render\PageRenderer;
use Magna\Users\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The builder's revision browser: list a document's history, render any
 * revision through the REAL themed pipeline for a visual side-by-side
 * (the SPA iframes current canvas next to a revision preview — one
 * renderer, so the diff is what publishing would actually produce), and
 * restore.
 *
 * Restore requires holding the document lock, exactly like a patch — it
 * rewrites the document out from under any other editor otherwise. The
 * restore itself is reversible: EntryManager snapshots current state as a
 * restore_point first.
 */
final class BuilderRevisionController
{
    use FindsDocuments;

    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly LockManager $locks,
        private readonly EntryManager $entries,
    ) {}

    public function index(string $id): JsonResponse
    {
        Gate::authorize('pages.content');
        $this->findDocument($id);

        $revisions = Revision::query()
            ->where('entry_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $authors = User::query()
            ->whereIn('id', $revisions->pluck('author_id')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'revisions' => $revisions->map(fn (Revision $revision): array => [
                'id' => $revision->id,
                'kind' => $revision->kind,
                'label' => $revision->label,
                'author' => $revision->author_id !== null ? ($authors[$revision->author_id] ?? null) : null,
                'createdAt' => $revision->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /** The revision's document rendered for an iframe — the visual half of the diff. */
    public function preview(string $id, string $revisionId): Response
    {
        Gate::authorize('pages.content');
        $entry = $this->findDocument($id);
        $revision = $this->revisionFor($id, $revisionId);

        $payload = $revision->payload;
        $document = $payload['blocks_data'] ?? [];
        $title = $payload['title'] ?? $entry->getAttribute('title');

        $html = $this->renderer->renderDocument(
            is_array($document) ? $document : [],
            is_string($title) ? $title : '',
            context: $entry,
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    public function restore(Request $request, string $id, string $revisionId): JsonResponse
    {
        Gate::authorize('pages.content');
        $this->findDocument($id);
        $revision = $this->revisionFor($id, $revisionId);

        /** @var User $user */
        $user = $request->user();

        // Same rule as a patch: rewriting the document requires the lock.
        if (! $this->locks->holds($id, $user)) {
            return response()->json(['message' => 'Another editor holds this document.'], 409);
        }

        $this->entries->restore($revision->id, (string) $user->getKey());

        return response()->json(['restored' => $revision->id]);
    }

    /** The revision, verified to belong to THIS document — never someone else's. */
    private function revisionFor(string $entryId, string $revisionId): Revision
    {
        $revision = Revision::query()->find(strtolower($revisionId));
        if ($revision === null || $revision->entry_id !== $entryId) {
            throw new NotFoundHttpException('Revision not found.');
        }

        return $revision;
    }
}
