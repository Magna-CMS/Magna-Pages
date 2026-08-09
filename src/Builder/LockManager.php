<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Database\UniqueConstraintViolationException;
use Magna\Users\User;

/**
 * Who may write a document right now (docs/magna-pages/03-BUILDER.md §2:
 * lock with takeover, v1 of concurrent editing).
 *
 * The unique index on entry_id is the mutex. Acquiring races through an
 * insert and lets the database pick the winner — a check-then-act here
 * would hand two browsers the same lock in the gap between the check and
 * the write.
 *
 * Takeover is deliberately not a permission fight: any actor who can edit
 * may take over, because the common case is one person's dead tab blocking
 * their own colleague. The protection is visibility (the response names who
 * held it) and the loser finding out on their next write instead of
 * silently overwriting.
 */
final class LockManager
{
    /** Seconds of silence after which a lock is stale. Heartbeat is ~30s. */
    public const TTL_SECONDS = 90;

    /**
     * Try to hold the lock for this user. Returns the live lock either way;
     * the caller checks whether it is theirs.
     */
    public function acquire(string $entryId, User $user): DocumentLock
    {
        $existing = DocumentLock::query()->where('entry_id', $entryId)->first();

        if ($existing !== null) {
            if ($existing->user_id === $user->getKey()) {
                return $this->refresh($existing);
            }

            if (! $existing->isStale(self::TTL_SECONDS)) {
                return $existing;
            }

            // Stale: the previous editor went quiet. Delete-then-insert
            // rather than update, so two takers of the same stale lock race
            // on the insert and exactly one wins.
            $existing->delete();
        }

        try {
            return DocumentLock::query()->create([
                'entry_id' => $entryId,
                'user_id' => $user->getKey(),
                'acquired_at' => now(),
                'heartbeat_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the insert race — whoever won holds it.
            /** @var DocumentLock $winner */
            $winner = DocumentLock::query()->where('entry_id', $entryId)->firstOrFail();

            return $winner;
        }
    }

    /**
     * Take the lock regardless of the current holder. The previous holder's
     * next write is refused — they learn immediately, not at publish time.
     */
    public function takeOver(string $entryId, User $user): DocumentLock
    {
        DocumentLock::query()->where('entry_id', $entryId)->delete();

        return $this->acquire($entryId, $user);
    }

    /** True when this user currently holds a fresh lock on the document. */
    public function holds(string $entryId, User $user): bool
    {
        $lock = DocumentLock::query()->where('entry_id', $entryId)->first();

        return $lock !== null
            && $lock->user_id === $user->getKey()
            && ! $lock->isStale(self::TTL_SECONDS);
    }

    /** The current live lock, if any (stale locks read as absent). */
    public function current(string $entryId): ?DocumentLock
    {
        $lock = DocumentLock::query()->where('entry_id', $entryId)->first();

        return $lock === null || $lock->isStale(self::TTL_SECONDS) ? null : $lock;
    }

    public function heartbeat(string $entryId, User $user): bool
    {
        $lock = DocumentLock::query()->where('entry_id', $entryId)->first();

        if ($lock === null || $lock->user_id !== $user->getKey()) {
            return false;
        }

        $this->refresh($lock);

        return true;
    }

    public function release(string $entryId, User $user): void
    {
        DocumentLock::query()
            ->where('entry_id', $entryId)
            ->where('user_id', $user->getKey())
            ->delete();
    }

    private function refresh(DocumentLock $lock): DocumentLock
    {
        $lock->heartbeat_at = now();
        $lock->save();

        return $lock;
    }
}
