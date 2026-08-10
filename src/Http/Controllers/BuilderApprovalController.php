<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Content\Entry;
use Magna\Pages\Builder\ApprovalManager;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Builder\PublishRequest;
use Magna\Users\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Approval workflow over HTTP. Requesting needs only content access — the
 * whole point is that the requester CANNOT publish; approving and
 * returning need the publish permission, same bar as the direct publish
 * button.
 */
final class BuilderApprovalController
{
    public function __construct(private readonly ApprovalManager $approvals) {}

    public function requestPublish(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $entry = Entry::type('page')->find($id);
        if ($entry === null) {
            throw new NotFoundHttpException('Page not found.');
        }

        $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        try {
            $created = $this->approvals->request(
                $entry,
                $this->actor($request),
                $request->string('note')->value() ?: null,
            );
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['request' => $this->payload($created)], 201);
    }

    /** The reviewer queue: everything waiting, oldest first. */
    public function queue(): JsonResponse
    {
        Gate::authorize('pages.publish');

        $pending = PublishRequest::query()
            ->where('status', PublishRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        $pages = Entry::type('page')->findMany($pending->pluck('entry_id'))->keyBy(
            fn (Entry $entry): string => (string) $entry->getKey(),
        );
        $requesters = User::query()->findMany($pending->pluck('requested_by'))->keyBy('id');

        return response()->json([
            'requests' => $pending->map(fn (PublishRequest $entry): array => [
                ...$this->payload($entry),
                'page' => [
                    'title' => $pages[$entry->entry_id]?->getAttribute('title'),
                    'slug' => $pages[$entry->entry_id]?->getAttribute('slug'),
                ],
                'requester' => $requesters[$entry->requested_by]?->name ?? 'Unknown user',
            ]),
        ]);
    }

    public function approve(Request $request, string $requestId): JsonResponse
    {
        Gate::authorize('pages.publish');

        try {
            $resolved = $this->approvals->approve($this->find($requestId), $this->actor($request));
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['request' => $this->payload($resolved)]);
    }

    public function returnRequest(Request $request, string $requestId): JsonResponse
    {
        Gate::authorize('pages.publish');

        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        try {
            $resolved = $this->approvals->return(
                $this->find($requestId),
                $this->actor($request),
                $request->string('note')->value(),
            );
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['request' => $this->payload($resolved)]);
    }

    private function find(string $id): PublishRequest
    {
        $request = PublishRequest::query()->find($id);
        if ($request === null) {
            throw new NotFoundHttpException('Publish request not found.');
        }

        return $request;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new NotFoundHttpException('No authenticated user.');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(PublishRequest $request): array
    {
        return [
            'id' => $request->id,
            'entry_id' => $request->entry_id,
            'status' => $request->status,
            'note' => $request->note,
            'resolution_note' => $request->resolution_note,
            'requested_at' => $request->created_at?->toIso8601String(),
            'resolved_at' => $request->resolved_at?->toIso8601String(),
        ];
    }
}
