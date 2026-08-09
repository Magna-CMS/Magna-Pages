<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * The one way a builder edit reaches storage.
 *
 * Order matters and is the whole security argument:
 *   1. authorize every operation (nothing applied yet)
 *   2. apply the batch to a copy
 *   3. validate the RESULT structurally
 *   4. save through EntryManager, which sanitizes at rest and records the
 *      revision
 *
 * Validating the result rather than the operations is what stops a
 * sequence of individually-innocent patches from assembling an invalid
 * document — spans that no longer sum to 12, a duplicated id, nesting past
 * the depth cap.
 */
final class DocumentEditor
{
    public const FIELD = 'blocks_data';

    public function __construct(
        private readonly PatchAuthorizer $authorizer,
        private readonly PatchApplier $applier,
        private readonly PageTreeValidator $validator,
        private readonly EntryManager $entries,
    ) {}

    /**
     * @param  list<array<mixed, mixed>>  $rawOperations
     * @return array<mixed, mixed> The document as stored after the batch
     *
     * @throws PatchException
     */
    public function applyPatch(Entry $entry, array $rawOperations, ?Authenticatable $actor): array
    {
        $operations = array_map(
            static fn (array $raw): PatchOperation => PatchOperation::fromArray($raw),
            $rawOperations,
        );

        $this->authorizer->authorize($operations, $actor);

        $document = $entry->getAttribute(self::FIELD);
        $patched = $this->applier->apply(is_array($document) ? $document : [], $operations);

        $errors = $this->validator->validate($patched);
        if ($errors !== []) {
            throw new PatchException($errors[0]);
        }

        $actorId = $actor instanceof Model ? $actor->getKey() : null;

        $this->entries->update($entry, [self::FIELD => $patched], is_string($actorId) ? $actorId : null);

        $stored = $entry->fresh()?->getAttribute(self::FIELD);

        return is_array($stored) ? $stored : $patched;
    }
}
