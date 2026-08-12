import type { NodeRect } from './bridge'

/**
 * Where a drag would drop.
 *
 * Pure geometry over the rects the bridge reports, so the rule that decides
 * "before this block or after it" is testable without a browser — and so the
 * indicator the user sees and the index actually patched are computed by the
 * same function. Two implementations of that rule would eventually disagree,
 * and the user would watch a block land somewhere other than where the line
 * was drawn.
 */

export interface DropTarget {
    /** The column the block would land in. */
    column: string
    /** Index within that column, counted before the dragged node is removed. */
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

export interface ColumnLayout {
    column: string
    rect: NodeRect
    blocks: NodeRect[]
}

/**
 * Group reported rects into columns with their blocks, in document order.
 *
 * Order comes from the caller's block-id lists rather than from geometry:
 * two blocks can share a vertical position (side-by-side floats, grid
 * children), and sorting those by `top` would silently reorder the document.
 */
export function layout(
    rects: NodeRect[],
    blocksByColumn: Record<string, string[]>,
): ColumnLayout[] {
    const byNode = new Map(rects.map((rect) => [rect.node, rect]))

    return rects
        .filter((rect) => rect.kind === 'column')
        .map((rect) => ({
            column: rect.node,
            rect,
            blocks: (blocksByColumn[rect.node] ?? [])
                .map((id) => byNode.get(id))
                .filter((entry): entry is NodeRect => entry !== undefined),
        }))
}

/**
 * The drop target for a pointer at (x, y) in canvas coordinates.
 *
 * Columns are matched by horizontal band, so dragging into an empty column
 * works — an empty column has no blocks to be "near", and a nearest-block
 * search would skip it entirely.
 */
export function dropTargetAt(columns: ColumnLayout[], x: number, y: number): DropTarget | null {
    const column = columns.find(
        (entry) =>
            x >= entry.rect.left &&
            x <= entry.rect.left + entry.rect.width &&
            y >= entry.rect.top &&
            y <= entry.rect.top + entry.rect.height,
    )

    if (!column) {
        return null
    }

    const indicatorFor = (top: number) => ({
        top,
        left: column.rect.left,
        width: column.rect.width,
    })

    if (column.blocks.length === 0) {
        return {
            column: column.column,
            index: 0,
            indicator: indicatorFor(column.rect.top),
        }
    }

    for (let index = 0; index < column.blocks.length; index++) {
        const block = column.blocks[index]
        const middle = block.top + block.height / 2

        if (y < middle) {
            return { column: column.column, index, indicator: indicatorFor(block.top) }
        }
    }

    const last = column.blocks[column.blocks.length - 1]

    return {
        column: column.column,
        index: column.blocks.length,
        indicator: indicatorFor(last.top + last.height),
    }
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
