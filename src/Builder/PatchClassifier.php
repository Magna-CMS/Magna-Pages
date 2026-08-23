<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * Decides what a patch operation IS, from the operation alone
 * (10-REVIEW-RESOLUTIONS.md §C3).
 *
 * The rule that makes this safe: **unclassified paths deny**. A pointer that
 * does not match one of the shapes below is refused rather than waved
 * through as "probably content" — so a future document key cannot become an
 * unguarded write surface by being forgotten here.
 */
final class PatchClassifier
{
    /** Structural container keys: touching one of these positionally moves nodes. */
    private const CONTAINERS = ['sections', 'columns', 'blocks', 'children'];

    /**
     * @throws PatchException
     */
    public function classify(PatchOperation $operation): PatchKind
    {
        $kind = $this->classifySegments($operation->segments, $operation->value);

        // A move's source is as consequential as its destination — classify
        // both and take the stricter (structure) reading.
        if ($operation->fromSegments !== null) {
            $this->classifySegments($operation->fromSegments, null);
        }

        return $kind;
    }

    /**
     * @param  list<string>  $segments
     *
     * @throws PatchException
     */
    private function classifySegments(array $segments, mixed $value): PatchKind
    {
        // Find the last named (non-index) segment — the one that says what
        // part of a node is being addressed.
        $named = null;
        $namedIndex = null;
        foreach ($segments as $index => $segment) {
            if (! $this->isPositional($segment)) {
                $named = $segment;
                $namedIndex = $index;
            }
        }

        if ($named === null || $namedIndex === null) {
            // Every segment is positional. In the legacy list form the
            // document ROOT is the sections container, so "/0" or "/-" is
            // adding/removing/reordering a section — structure, same as
            // "/sections/0" is in the wrapped form.
            return PatchKind::Structure;
        }

        // Positional tail after a container key = adding/removing/reordering
        // a node in that container. "/sections/0/columns/1" is structure even
        // though nothing named follows.
        if (in_array($named, self::CONTAINERS, true) && $namedIndex === count($segments) - 2) {
            return PatchKind::Structure;
        }
        if (in_array($named, self::CONTAINERS, true) && $namedIndex === count($segments) - 1) {
            return PatchKind::Structure;
        }

        // Everything below a node's `data` is field content — unless the
        // value being written is a dynamic binding, which is a different
        // (higher) capability than typing text.
        if ($this->addressesUnder($segments, 'data')) {
            return $this->isBinding($value) ? PatchKind::Binding : PatchKind::Content;
        }

        if ($this->addressesUnder($segments, 'settings')) {
            // Conditions and locks live in settings but are not styling.
            if (in_array('conditions', $segments, true)) {
                return PatchKind::Condition;
            }
            if (in_array('locked', $segments, true)) {
                return PatchKind::Lock;
            }
            // A node's name is editorial metadata: it never reaches the
            // page, and gating it behind the design permission would mean
            // an editor cannot name the thing they are editing.
            if (in_array('label', $segments, true)) {
                return PatchKind::Content;
            }
            /*
             * Switching a node off takes it off the page. That is the same
             * kind of decision as removing it — reversible, but structural
             * — and calling it styling would let anyone who may recolour a
             * heading also make it disappear for every visitor.
             */
            if (in_array('hidden', $segments, true)) {
                return PatchKind::Structure;
            }

            return PatchKind::Style;
        }

        // A node's own identity/type keys are structural: rewriting a block's
        // handle swaps the block out from under its data.
        if (in_array($named, ['id', 'type', 'block', 'span', 'part'], true)) {
            return PatchKind::Structure;
        }

        throw new PatchException("Patch path addresses an unrecognised document key: \"{$named}\".");
    }

    /** @param list<string> $segments */
    private function addressesUnder(array $segments, string $key): bool
    {
        $position = array_search($key, $segments, true);

        // Must be a real container step, not the trailing segment name alone —
        // "/sections/0/columns/0/blocks/0/data/text" yes, "/data" alone no.
        return $position !== false && $position < count($segments) - 1;
    }

    private function isPositional(string $segment): bool
    {
        return $segment === '-' || ctype_digit($segment);
    }

    private function isBinding(mixed $value): bool
    {
        return is_array($value) && array_key_exists('$bind', $value);
    }
}
