<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Illuminate\Support\Str;

/**
 * The editor's view of a menu: a FLAT list of rows, each carrying a depth.
 *
 * Menus are stored nested (`parent_id`) and rendered nested, but they are
 * EDITED flat — which is how WordPress does it, and not by accident. Every
 * gesture the builder offers is a move along one of two axes: up/down the
 * list, or left/right in depth. On a nested array each of those is a
 * different splice at a different level; on a flat list with a depth
 * number they are all the same two operations, and dragging is just the
 * two of them at once.
 *
 * A row owns the rows below it with a greater depth — that is what makes a
 * subtree, and every move here carries a row's descendants with it. The
 * alternative, moving a parent out from under its children, is how a menu
 * editor loses somebody's work.
 *
 * Pure functions on arrays: no Livewire, no database, so the rules are
 * testable without either.
 */
final class MenuTree
{
    /**
     * How deep the builder allows nesting.
     *
     * The store has no limit (`parent_id` is just a column) and the
     * renderer walks whatever it is given, so this is a UI judgement: past
     * a few levels a drop-down menu stops being navigable, and the depth
     * that cannot be reached cannot be created by accident.
     */
    public const MAX_DEPTH = 5;

    /**
     * A stored nested tree as flat editor rows.
     *
     * @param  list<array<string, mixed>>  $nested
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $nested, int $depth = 0): array
    {
        $rows = [];

        foreach ($nested as $node) {
            if (! is_array($node)) {
                continue;
            }

            $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
            unset($node['children']);

            $node['depth'] = $depth;
            $node['key'] ??= (string) Str::ulid();
            $rows[] = $node;

            foreach (self::flatten($children, $depth + 1) as $child) {
                $rows[] = $child;
            }
        }

        return $rows;
    }

    /**
     * Flat editor rows back to the nested tree the manager stores.
     *
     * A row deeper than the one before it can only be its child, so the
     * nesting is implied by the depths alone — no parent ids to keep in
     * step with the ordering.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function nest(array $rows): array
    {
        return self::assemble(self::normalise($rows), 0, 0)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function assemble(array $rows, int $index, int $depth): array
    {
        $level = [];

        while ($index < count($rows)) {
            $row = $rows[$index];
            $rowDepth = (int) ($row['depth'] ?? 0);

            if ($rowDepth < $depth) {
                break;
            }

            unset($row['depth'], $row['key']);
            [$children, $index] = self::assemble($rows, $index + 1, $depth + 1);
            if ($children !== []) {
                $row['children'] = $children;
            }

            $level[] = $row;
        }

        return [$level, $index];
    }

    /**
     * Rows with impossible depths corrected.
     *
     * A row can only ever be one level deeper than the row above it: the
     * first row is a root, and nothing can be a child of a row that is not
     * there. Applied on the way in AND on the way out, so a hand-edited
     * payload or a dragged row that overshot cannot store a tree the
     * renderer would have to guess at.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $rows): array
    {
        $out = [];
        $previous = -1;

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $depth = max(0, min(self::MAX_DEPTH, (int) ($row['depth'] ?? 0)));
            $row['depth'] = min($depth, $previous + 1);
            $previous = (int) $row['depth'];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * How many rows after `$index` belong to it — its descendants, which
     * every move carries along.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function subtreeLength(array $rows, int $index): int
    {
        $depth = (int) ($rows[$index]['depth'] ?? 0);
        $length = 0;

        for ($i = $index + 1; $i < count($rows); $i++) {
            if ((int) ($rows[$i]['depth'] ?? 0) <= $depth) {
                break;
            }
            $length++;
        }

        return $length;
    }

    /**
     * Move a row and its descendants above the previous row at the same
     * depth — past that row's whole subtree, so it steps over a sibling
     * rather than into it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function moveUp(array $rows, int $index): array
    {
        if (! isset($rows[$index]) || $index === 0) {
            return $rows;
        }

        $depth = (int) $rows[$index]['depth'];

        // The previous row at this depth or shallower is where it lands.
        $target = null;
        for ($i = $index - 1; $i >= 0; $i--) {
            if ((int) $rows[$i]['depth'] <= $depth) {
                $target = $i;
                break;
            }
        }

        if ($target === null) {
            return $rows;
        }

        $subtree = array_splice($rows, $index, self::subtreeLength($rows, $index) + 1);
        array_splice($rows, $target, 0, $subtree);

        return self::normalise($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function moveDown(array $rows, int $index): array
    {
        if (! isset($rows[$index])) {
            return $rows;
        }

        $length = self::subtreeLength($rows, $index) + 1;
        $after = $index + $length;
        if (! isset($rows[$after])) {
            return $rows;
        }

        // Step over the next sibling's whole subtree, not into it.
        $nextLength = self::subtreeLength($rows, $after) + 1;
        $subtree = array_splice($rows, $index, $length);
        array_splice($rows, $index + $nextLength, 0, $subtree);

        return self::normalise($rows);
    }

    /**
     * Nest a row under the one above it. Refused when there is nothing to
     * nest under, or when the subtree would end up deeper than the cap.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function indent(array $rows, int $index): array
    {
        if (! isset($rows[$index]) || $index === 0) {
            return $rows;
        }

        $depth = (int) $rows[$index]['depth'];
        if ((int) $rows[$index - 1]['depth'] < $depth) {
            // Already the first child of the row above it.
            return $rows;
        }

        $length = self::subtreeLength($rows, $index);
        $deepest = $depth;
        for ($i = $index; $i <= $index + $length; $i++) {
            $deepest = max($deepest, (int) $rows[$i]['depth']);
        }

        if ($deepest + 1 > self::MAX_DEPTH) {
            return $rows;
        }

        for ($i = $index; $i <= $index + $length; $i++) {
            $rows[$i]['depth'] = (int) $rows[$i]['depth'] + 1;
        }

        return self::normalise($rows);
    }

    /**
     * Lift a row out from under its parent, descendants included.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function outdent(array $rows, int $index): array
    {
        if (! isset($rows[$index]) || (int) $rows[$index]['depth'] === 0) {
            return $rows;
        }

        $length = self::subtreeLength($rows, $index);
        for ($i = $index; $i <= $index + $length; $i++) {
            $rows[$i]['depth'] = max(0, (int) $rows[$i]['depth'] - 1);
        }

        return self::normalise($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function toTop(array $rows, int $index): array
    {
        if (! isset($rows[$index]) || $index === 0) {
            return $rows;
        }

        $length = self::subtreeLength($rows, $index) + 1;
        $subtree = array_splice($rows, $index, $length);

        // It becomes a root item: the depth it had under a parent means
        // nothing at the top of the list.
        $shift = (int) $subtree[0]['depth'];
        foreach ($subtree as $position => $row) {
            $subtree[$position]['depth'] = max(0, (int) $row['depth'] - $shift);
        }

        array_splice($rows, 0, 0, $subtree);

        return self::normalise($rows);
    }

    /**
     * Remove a row and everything under it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function remove(array $rows, int $index): array
    {
        if (! isset($rows[$index])) {
            return $rows;
        }

        array_splice($rows, $index, self::subtreeLength($rows, $index) + 1);

        return self::normalise($rows);
    }

    /**
     * Drop a row (with its descendants) at a new position and depth — the
     * one operation a drag performs.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function move(array $rows, int $from, int $to, int $depth): array
    {
        if (! isset($rows[$from])) {
            return $rows;
        }

        $length = self::subtreeLength($rows, $from) + 1;

        // Dropping a row inside its own subtree would detach it from the
        // list entirely; the drag layer refuses it too, but this is the
        // side that cannot be bypassed.
        if ($to > $from && $to < $from + $length) {
            return $rows;
        }

        $subtree = array_splice($rows, $from, $length);
        $shift = $depth - (int) $subtree[0]['depth'];

        foreach ($subtree as $position => $row) {
            $subtree[$position]['depth'] = max(0, min(self::MAX_DEPTH, (int) $row['depth'] + $shift));
        }

        $at = $to > $from ? $to - $length : $to;
        array_splice($rows, max(0, min(count($rows), $at)), 0, $subtree);

        return self::normalise($rows);
    }
}
