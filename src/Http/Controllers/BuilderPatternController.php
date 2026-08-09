<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Builder\PatternManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * My Library over HTTP. Saving needs the layout permission (a pattern is
 * reusable structure); listing and instantiating need only content access —
 * what an actor may INSERT is decided by the patch authorizer when the
 * instance comes back as a patch, same wall as every other write.
 */
final class BuilderPatternController
{
    public function __construct(private readonly PatternManager $patterns) {}

    public function index(): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json([
            'patterns' => Pattern::query()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'kind'])
                ->map(fn (Pattern $pattern): array => [
                    'id' => $pattern->id,
                    'name' => $pattern->name,
                    'kind' => $pattern->kind,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('pages.layout');

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string'],
            'node' => ['required', 'array'],
        ]);

        try {
            $pattern = $this->patterns->save(
                $request->string('name')->value(),
                $request->string('kind')->value(),
                (array) $request->input('node'),
                is_string($request->user()?->getAuthIdentifier()) ? $request->user()->getAuthIdentifier() : null,
            );
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $pattern->id, 'name' => $pattern->name, 'kind' => $pattern->kind], 201);
    }

    /** A fresh-id copy to insert — never the stored subtree itself. */
    public function instance(string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $pattern = Pattern::query()->find($id);
        if ($pattern === null) {
            throw new NotFoundHttpException('Pattern not found.');
        }

        return response()->json([
            'kind' => $pattern->kind,
            'node' => $this->patterns->instance($pattern),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.layout');

        Pattern::query()->whereKey($id)->delete();

        return response()->json(['deleted' => true]);
    }
}
