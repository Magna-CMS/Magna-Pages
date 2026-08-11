<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Builder\FindsDocuments;
use Magna\Pages\Comments\PageComment;
use Magna\Users\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Node-anchored comments (§F): editorial discussion on a document or one
 * of its nodes. Content-tier — commenting is part of editing, and nothing
 * here touches the document itself. Bodies are stored and returned as
 * plain text; the SPA renders them as text, never HTML.
 */
final class BuilderCommentController
{
    use FindsDocuments;

    public function index(string $id): JsonResponse
    {
        Gate::authorize('pages.content');
        $this->findDocument($id);

        $authors = [];
        $comments = PageComment::query()
            ->where('page_id', $id)
            ->orderBy('created_at')
            ->get();

        $authorIds = array_values(array_filter($comments->pluck('author_id')->unique()->all()));
        if ($authorIds !== []) {
            $authors = User::query()->whereIn('id', $authorIds)->pluck('name', 'id')->all();
        }

        return response()->json(['comments' => $comments->map(fn (PageComment $c): array => [
            'id' => $c->id,
            'nodeId' => $c->node_id,
            'body' => $c->body,
            'author' => $c->author_id !== null ? ($authors[$c->author_id] ?? null) : null,
            'resolved' => $c->resolved_at !== null,
            'createdAt' => $c->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');
        $this->findDocument($id);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'nodeId' => ['nullable', 'string', 'max:100'],
        ]);

        $comment = PageComment::query()->create([
            'page_id' => $id,
            'node_id' => $validated['nodeId'] ?? null,
            'author_id' => auth()->id() !== null ? (string) auth()->id() : null,
            'body' => $validated['body'],
        ]);

        return response()->json(['comment' => ['id' => $comment->id]], 201);
    }

    public function resolve(string $id, string $commentId): JsonResponse
    {
        Gate::authorize('pages.content');
        $this->findDocument($id);

        $comment = PageComment::query()
            ->where('page_id', $id)
            ->find($commentId);
        if ($comment === null) {
            throw new NotFoundHttpException('Comment not found.');
        }

        $comment->update(['resolved_at' => now()]);

        return response()->json(['resolved' => true]);
    }
}
