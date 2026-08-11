<?php

declare(strict_types=1);

namespace Magna\Pages\Changesets;

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;

/**
 * Changesets (§A3): "these documents go live together." A changeset is a
 * named list of documents; publishing it publishes every member — pending
 * drafts fold into their published rows, unpublished entries go live — in
 * ONE transaction. Any member failing aborts the whole set: the promise
 * is atomicity, and silently skipping a member would break exactly the
 * coordinated-redesign case changesets exist for.
 */
class ChangesetManager
{
    /** Document types a changeset may contain (the builder's own set). */
    private const DOCUMENT_TYPES = ['page', 'pages_template'];

    public function __construct(private readonly EntryManager $entries) {}

    public function create(string $name, ?string $actorId = null): Changeset
    {
        return Changeset::query()->create([
            'name' => $name,
            'status' => Changeset::STATUS_OPEN,
            'author_id' => $actorId,
        ]);
    }

    /**
     * Add a document. Idempotent — adding twice is once.
     *
     * @throws \InvalidArgumentException
     */
    public function add(Changeset $changeset, string $entryType, string $entryId): void
    {
        if ($changeset->status !== Changeset::STATUS_OPEN) {
            throw new \InvalidArgumentException('This changeset has already been published.');
        }
        if (! in_array($entryType, self::DOCUMENT_TYPES, true)) {
            throw new \InvalidArgumentException("A changeset cannot contain \"{$entryType}\" entries.");
        }
        if (Entry::type($entryType)->whereKey($entryId)->doesntExist()) {
            throw new \InvalidArgumentException('No such document.');
        }

        DB::table('pages_changeset_items')->insertOrIgnore([
            'changeset_id' => $changeset->id,
            'entry_type' => $entryType,
            'entry_id' => $entryId,
        ]);
    }

    /** @return list<array{entry_type: string, entry_id: string}> */
    public function items(Changeset $changeset): array
    {
        /** @var list<array{entry_type: string, entry_id: string}> */
        return DB::table('pages_changeset_items')
            ->where('changeset_id', $changeset->id)
            ->orderBy('entry_id')
            ->get(['entry_type', 'entry_id'])
            ->map(fn (object $row): array => ['entry_type' => (string) $row->entry_type, 'entry_id' => (string) $row->entry_id])
            ->all();
    }

    /**
     * Publish every member atomically.
     *
     * @throws \RuntimeException when the set is empty, already published,
     *                           or any member cannot publish — in which
     *                           case NOTHING published.
     */
    public function publish(Changeset $changeset, ?string $actorId = null): void
    {
        if ($changeset->status !== Changeset::STATUS_OPEN) {
            throw new \RuntimeException('This changeset has already been published.');
        }

        $items = $this->items($changeset);
        if ($items === []) {
            throw new \RuntimeException('An empty changeset publishes nothing — add documents first.');
        }

        DB::transaction(function () use ($changeset, $items, $actorId): void {
            foreach ($items as $item) {
                /** @var Entry|null $entry */
                $entry = Entry::type($item['entry_type'])->whereKey($item['entry_id'])->first();
                if ($entry === null) {
                    throw new \RuntimeException(
                        "Document {$item['entry_id']} no longer exists — nothing in the changeset was published."
                    );
                }
                $this->entries->publish($entry, actorId: $actorId);
            }

            $changeset->update([
                'status' => Changeset::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        });
    }
}
