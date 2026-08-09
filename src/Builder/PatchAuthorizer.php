<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Contracts\Auth\Authenticatable;
use Magna\Blocks\BlockRegistry;
use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * Server-side enforcement of client-safe mode (10-REVIEW-RESOLUTIONS.md §C3).
 *
 * Every operation in a batch is classified and checked against the actor
 * before ANY of them is applied. That ordering is the point: a batch that
 * ends with an unauthorized delete never gets to perform the authorized
 * edits that preceded it, so a client cannot smuggle one refused operation
 * through by burying it in a legitimate gesture.
 *
 * Hand-crafted patches, clipboard pastes and UI actions all arrive here —
 * there is no path into the document that skips it.
 */
final class PatchAuthorizer
{
    public function __construct(
        private readonly PatchClassifier $classifier,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * @param  list<PatchOperation>  $operations
     *
     * @throws PatchException
     */
    public function authorize(array $operations, ?Authenticatable $actor): void
    {
        // A null actor is a system context (CLI, import, demo seeding) —
        // the same stance the document authorizer takes. Requests always
        // carry an actor; the controller is what guarantees that.
        if ($actor === null) {
            return;
        }

        foreach ($operations as $operation) {
            $kind = $this->classifier->classify($operation);

            $this->requirePermission($actor, $kind->permission(), $kind);
            $this->authorizeInsertedBlocks($operation, $actor);
        }
    }

    /**
     * Blocks may declare their own permission (`requiresPermission`), which
     * is checked per insertion — a raw-HTML block is not something every
     * layout editor may drop onto a page.
     *
     * @throws PatchException
     */
    private function authorizeInsertedBlocks(PatchOperation $operation, Authenticatable $actor): void
    {
        if ($operation->op === 'remove' || $operation->value === null) {
            return;
        }

        foreach ($this->handlesIn($operation->value) as $handle) {
            $definition = $this->blocks->get($handle);
            if ($definition === null) {
                throw new PatchException("Unknown block handle in patch: \"{$handle}\".");
            }

            $required = $definition->requiresPermission;
            if ($required !== null && ! $actor->can($required)) {
                throw new PatchException("Inserting the \"{$handle}\" block needs the \"{$required}\" permission.");
            }
        }
    }

    /**
     * Every block handle inside an inserted value, at any depth (a pasted
     * section carries columns carrying blocks carrying children).
     *
     * @return list<string>
     */
    private function handlesIn(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $handles = [];

        if (isset($value['block']) && is_string($value['block']) && $value['block'] !== '') {
            $handles[] = $value['block'];
        }

        foreach ($value as $key => $child) {
            // Field data may legitimately contain a "block" key of its own;
            // only walk the structural containers.
            if (in_array($key, ['columns', 'blocks', 'children', 'sections'], true) && is_array($child)) {
                foreach ($child as $node) {
                    $handles = [...$handles, ...$this->handlesIn($node)];
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @throws PatchException
     */
    private function requirePermission(Authenticatable $actor, string $permission, PatchKind $kind): void
    {
        if (! $actor->can($permission)) {
            throw new PatchException("This edit ({$kind->value}) needs the \"{$permission}\" permission.");
        }
    }
}
