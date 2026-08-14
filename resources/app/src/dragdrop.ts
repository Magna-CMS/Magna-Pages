import type { NodeRect } from './bridge'
import type { BlockParentKind } from './document/placement'

/**
 * Where a drag would drop.
 *
 * Pure geometry over the rects the bridge reports, so the rule that decides
 * "before this block or after it" is testable without a browser — and so the
 * indicator the user sees and the index actually patched are computed by the
 * same function. Two implementations of that rule would eventually disagree,
 * and the user would watch a block land somewhere other than where the line
 * was drawn.
 *
 * A container block holds blocks exactly the way a column does, so it is a
 * drop target on the same terms — one `ParentLayout` list covers both, and
 * the innermost parent under the pointer wins. Anything else would make a
 * container impossible to aim at: its rect is always inside its column's.
 */

export interface DropTarget {
    /** The node the block would land in: a column, or a container block. */
    parent: string
    kind: BlockParentKind
    /** Index within that parent, counted before the dragged node is removed. */
    index: number
    /** Where to draw the insertion line. */
    indicator: { top: number; left: number; width: number }
}

/** Where a section-shaped payload would land: between two sections. */
export interface SectionDropTarget {
    /** Index in the document's section list. */
    index: number
    indicator: { top: number; left: number; width: number }
}

/** One place blocks can be dropped, as the document describes it. */
export interface ParentInfo {
    id: string
    kind: BlockParentKind
    /** Depth of blocks placed here — a column's blocks are depth 1. */
    depth: number
    /** Direct children, in document order. */
    blockIds: string[]
}

export interface ParentLayout {
    parent: string
    kind: BlockParentKind
    depth: number
    rect: NodeRect
    blocks: NodeRect[]
}

/**
 * What the drag is carrying, and what the document will accept.
 *
 * Advisory only — the server refuses the same things through
 * PageTreeValidator and PatchAuthorizer. This exists so the indicator is
 * never drawn somewhere the drop would then be rejected, which reads as the
 * builder losing the block.
 */
export interface DropRules {
    /** Deepest block level the server stores (PageTreeValidator::MAX_BLOCK_DEPTH). */
    maxDepth: number
    /** Levels the payload itself occupies — 1 for a plain block. */
    height: number
    /** Parents the payload may not land in: itself and its descendants. */
    forbidden: string[]
}

/**
 * Group reported rects into the parents that hold them, in document order.
 *
 * Order comes from the caller's block-id lists rather than from geometry:
 * two blocks can share a vertical position (side-by-side floats, grid
 * children), and sorting those by `top` would silently reorder the document.
 */
export function layout(rects: NodeRect[], parents: ParentInfo[]): ParentLayout[] {
    const byNode = new Map(rects.map((rect) => [rect.node, rect]))

    return parents
        .map((parent) => {
            const rect = byNode.get(parent.id)
            if (rect === undefined) {
                return null
            }

            return {
                parent: parent.id,
                kind: parent.kind,
                depth: parent.depth,
                rect,
                blocks: parent.blockIds
                    .map((id) => byNode.get(id))
                    .filter((entry): entry is NodeRect => entry !== undefined),
            }
        })
        .filter((entry): entry is ParentLayout => entry !== null)
}

/**
 * The drop target for a pointer at (x, y) in canvas coordinates.
 *
 * Parents are matched by their box, so dragging into an EMPTY column or an
 * empty container works — neither has blocks to be "near", and a
 * nearest-block search would skip both entirely.
 *
 * The innermost matching parent wins: a container's box is inside its
 * column's, so preferring the outer one would make containers unreachable.
 */
export function dropTargetAt(
    parents: ParentLayout[],
    x: number,
    y: number,
    rules?: DropRules,
): DropTarget | null {
    let parent: ParentLayout | null = null

    for (const entry of parents) {
        const inside =
            x >= entry.rect.left &&
            x <= entry.rect.left + entry.rect.width &&
            y >= entry.rect.top &&
            y <= entry.rect.top + entry.rect.height

        if (!inside || !accepts(entry, rules)) {
            continue
        }

        if (parent === null || entry.depth >= parent.depth) {
            parent = entry
        }
    }

    if (!parent) {
        return null
    }

    const holder = parent
    const indicatorFor = (top: number) => ({
        top,
        left: holder.rect.left,
        width: holder.rect.width,
    })

    if (holder.blocks.length === 0) {
        return {
            parent: holder.parent,
            kind: holder.kind,
            index: 0,
            indicator: indicatorFor(holder.rect.top),
        }
    }

    for (let index = 0; index < holder.blocks.length; index++) {
        const block = holder.blocks[index]
        const middle = block.top + block.height / 2

        if (y < middle) {
            return {
                parent: holder.parent,
                kind: holder.kind,
                index,
                indicator: indicatorFor(block.top),
            }
        }
    }

    const last = holder.blocks[holder.blocks.length - 1]

    return {
        parent: holder.parent,
        kind: holder.kind,
        index: holder.blocks.length,
        indicator: indicatorFor(last.top + last.height),
    }
}

/** Whether this parent may hold what the drag is carrying. */
function accepts(parent: ParentLayout, rules?: DropRules): boolean {
    if (!rules) {
        return true
    }

    // Itself or one of its own descendants: a node cannot contain itself,
    // and the server refuses the move outright.
    if (rules.forbidden.includes(parent.parent)) {
        return false
    }

    return parent.depth + rules.height - 1 <= rules.maxDepth
}

/**
 * Where a SECTION-shaped payload would land — a pattern, a template part,
 * or a section being reordered.
 *
 * Sections stack vertically and span the canvas, so the rule is the
 * mirror of the block rule with one difference: a pointer past the last
 * section appends rather than returning nothing, because the area below
 * the final section is the most natural place to aim for "put it at the
 * end" and refusing there would feel broken.
 */
export function sectionDropTargetAt(
    sections: NodeRect[],
    y: number,
    canvasWidth: number,
): SectionDropTarget | null {
    if (sections.length === 0) {
        return { index: 0, indicator: { top: 0, left: 0, width: canvasWidth } }
    }

    const ordered = [...sections]

    for (let index = 0; index < ordered.length; index++) {
        const section = ordered[index]
        const middle = section.top + section.height / 2

        if (y < middle) {
            return {
                index,
                indicator: { top: section.top, left: section.left, width: section.width },
            }
        }
    }

    const last = ordered[ordered.length - 1]

    return {
        index: ordered.length,
        indicator: { top: last.top + last.height, left: last.left, width: last.width },
    }
}

/** The section rects, in the document order the caller supplies. */
export function sectionLayout(rects: NodeRect[], sectionIds: string[]): NodeRect[] {
    const byNode = new Map(rects.map((rect) => [rect.node, rect]))

    return sectionIds
        .map((id) => byNode.get(id))
        .filter((entry): entry is NodeRect => entry !== undefined)
}

/** A drag has to travel before it counts, or every click becomes a move. */
export const DRAG_THRESHOLD = 4

export function exceedsThreshold(from: { x: number; y: number }, to: { x: number; y: number }): boolean {
    return Math.abs(to.x - from.x) > DRAG_THRESHOLD || Math.abs(to.y - from.y) > DRAG_THRESHOLD
}
