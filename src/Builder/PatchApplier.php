<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * Applies builder patches to a block document.
 *
 * Works on a copy and returns a new document: a batch either lands whole or
 * not at all, so a rejected operation halfway through a gesture cannot leave
 * the stored document in a state no editor ever produced.
 *
 * Only the raw array is touched here — no normalisation, no id generation,
 * no key reordering. That is deliberate: the tolerant-reader guarantee
 * (docs/block-document-format.md §3) says unknown keys survive editing, and
 * the surest way to keep that true is for the write path never to rebuild
 * nodes it did not have to.
 */
final class PatchApplier
{
    /** Ceiling on one batch — a gesture is tens of operations, not thousands. */
    public const MAX_OPERATIONS = 200;

    /**
     * @param  array<mixed, mixed>  $document
     * @param  list<PatchOperation>  $operations
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    public function apply(array $document, array $operations): array
    {
        if (count($operations) > self::MAX_OPERATIONS) {
            throw new PatchException('Too many operations in one patch batch.');
        }

        $result = $document;

        foreach ($operations as $operation) {
            $result = $this->applyOne($result, $operation);
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed>  $document
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    private function applyOne(array $document, PatchOperation $operation): array
    {
        if ($operation->op === 'move') {
            /** @var list<string> $fromSegments */
            $fromSegments = $operation->fromSegments ?? [];
            $moved = $this->read($document, $fromSegments);
            $document = $this->write($document, $fromSegments, 'remove', null);

            return $this->write($document, $operation->segments, 'add', $moved);
        }

        return $this->write($document, $operation->segments, $operation->op, $operation->value);
    }

    /**
     * @param  array<mixed, mixed>  $document
     * @param  list<string>  $segments
     *
     * @throws PatchException
     */
    private function read(array $document, array $segments): mixed
    {
        $cursor = $document;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw new PatchException('Patch source path does not exist in the document.');
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Rewrite one location in the document. Recursive rather than by
     * reference: PHP arrays are copy-on-write values, and rebuilding the
     * spine on the way out is what makes a failed operation leave the
     * caller's array untouched.
     *
     * @param  array<mixed, mixed>  $node
     * @param  list<string>  $segments
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    private function write(array $node, array $segments, string $op, mixed $value): array
    {
        $key = $segments[0];
        $isLast = count($segments) === 1;

        if (! $isLast) {
            if (! array_key_exists($key, $node) || ! is_array($node[$key])) {
                throw new PatchException('Patch path does not exist in the document.');
            }

            $node[$key] = $this->write($node[$key], array_slice($segments, 1), $op, $value);

            return $node;
        }

        $isList = $node !== [] && array_is_list($node);

        return match ($op) {
            'add' => $this->insert($node, $key, $value, $isList),
            'replace' => $this->replace($node, $key, $value),
            'remove' => $this->delete($node, $key, $isList),
            default => throw new PatchException('Unsupported patch operation.'),
        };
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    private function insert(array $node, string $key, mixed $value, bool $isList): array
    {
        if ($key === '-') {
            $node[] = $value;

            return $node;
        }

        // Into a list, "add" means insert-before (RFC 6902), not overwrite —
        // the difference between dropping a block into position 2 and
        // destroying whatever was already there.
        if ($isList && ctype_digit($key)) {
            $index = (int) $key;
            if ($index > count($node)) {
                throw new PatchException('Patch index is past the end of the list.');
            }

            array_splice($node, $index, 0, [$value]);

            return $node;
        }

        $node[$key] = $value;

        return $node;
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    private function replace(array $node, string $key, mixed $value): array
    {
        if (! array_key_exists($key, $node)) {
            throw new PatchException('Patch path does not exist in the document.');
        }

        $node[$key] = $value;

        return $node;
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @return array<mixed, mixed>
     *
     * @throws PatchException
     */
    private function delete(array $node, string $key, bool $isList): array
    {
        if (! array_key_exists($key, $node)) {
            throw new PatchException('Patch path does not exist in the document.');
        }

        unset($node[$key]);

        // Removing from a list must close the gap, or every later pointer in
        // the same batch would address the wrong node.
        return $isList ? array_values($node) : $node;
    }
}
