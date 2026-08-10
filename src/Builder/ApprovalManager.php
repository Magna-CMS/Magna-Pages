<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Users\User;

/**
 * The approval conversation: request, approve, return.
 *
 * Approving PUBLISHES — one action, because "approved but somebody still
 * has to remember to publish" is a queue that silently rots. The publish
 * runs under the reviewer's identity through the ordinary EntryManager
 * path, so revisions, events and cache purges all record who actually
 * shipped it.
 *
 * One open request per page: a second "request publish" while one is
 * pending would either duplicate the reviewer's work or race it.
 */
final class ApprovalManager
{
    public function __construct(private readonly EntryManager $entries) {}

    /**
     * @throws PatchException
     */
    public function request(Entry $entry, User $requester, ?string $note): PublishRequest
    {
        $open = $this->pendingFor((string) $entry->getKey());
        if ($open !== null) {
            throw new PatchException('A publish request for this page is already waiting for review.');
        }

        return PublishRequest::query()->create([
            'entry_id' => (string) $entry->getKey(),
            'requested_by' => (string) $requester->getKey(),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            // Explicit rather than relying on the column default: the
            // in-memory model after create() would otherwise carry null.
            'status' => PublishRequest::STATUS_PENDING,
        ]);
    }

    /**
     * @throws PatchException
     */
    public function approve(PublishRequest $request, User $reviewer): PublishRequest
    {
        $this->assertPending($request);

        $entry = Entry::type('page')->find($request->entry_id);
        if ($entry === null) {
            // The page was deleted while the request waited; close the
            // request rather than approving a ghost.
            $this->resolve($request, PublishRequest::STATUS_RETURNED, $reviewer, 'The page no longer exists.');

            throw new PatchException('That page no longer exists; the request has been closed.');
        }

        $this->entries->publish($entry, actorId: (string) $reviewer->getKey());

        return $this->resolve($request, PublishRequest::STATUS_APPROVED, $reviewer, null);
    }

    /**
     * @throws PatchException
     */
    public function return(PublishRequest $request, User $reviewer, string $note): PublishRequest
    {
        $this->assertPending($request);

        if (trim($note) === '') {
            // "Returned" with no reason teaches the requester nothing and
            // guarantees the same request comes back unchanged.
            throw new PatchException('Returning a request needs a note saying what to change.');
        }

        return $this->resolve($request, PublishRequest::STATUS_RETURNED, $reviewer, trim($note));
    }

    public function pendingFor(string $entryId): ?PublishRequest
    {
        return PublishRequest::query()
            ->where('entry_id', $entryId)
            ->where('status', PublishRequest::STATUS_PENDING)
            ->first();
    }

    /**
     * @throws PatchException
     */
    private function assertPending(PublishRequest $request): void
    {
        if ($request->status !== PublishRequest::STATUS_PENDING) {
            // Two reviewers acting on one request: the second learns the
            // first got there, instead of silently re-resolving.
            throw new PatchException('This request has already been resolved.');
        }
    }

    private function resolve(PublishRequest $request, string $status, User $reviewer, ?string $note): PublishRequest
    {
        $request->forceFill([
            'status' => $status,
            'resolved_by' => (string) $reviewer->getKey(),
            'resolution_note' => $note,
            'resolved_at' => now(),
        ])->save();

        return $request;
    }
}
