/**
 * What can be dragged, and where each thing is allowed to land.
 *
 * One table, read by both halves: the drop-target search consults it to
 * decide whether to draw an indicator at all, and the commit consults it
 * to decide which edit producer to call. Two tables would eventually
 * disagree and the user would watch a drop be accepted and then do
 * nothing.
 *
 * Adding an asset kind is adding a row here — that is the whole
 * extensibility contract, and it is honest only while nothing else
 * branches on `kind`.
 */

/** Where a payload is allowed to land. */
export type Placement = 'column' | 'canvas' | 'import'

export type DragSource =
    /** A block from the element library, not yet in the document. */
    | { kind: 'new'; handle: string }
    /** A node already in the document, being moved. */
    | { kind: 'move'; nodeId: string }
    /** A saved pattern (section subtree, or a single block). */
    | { kind: 'pattern'; id: string; assetKind: string }
    /** A cloud-library asset. */
    | { kind: 'library'; slug: string; assetKind: string }

/**
 * Cloud/pattern asset kinds and their placement. A future kind adds one
 * entry; nothing else in the builder needs to know it exists.
 */
export const ASSET_PLACEMENT: Record<string, Placement> = {
    /** One block node — belongs inside a column. */
    block: 'column',
    /** One section subtree — belongs between sections. */
    section: 'canvas',
    pattern: 'canvas',
    /** A list of sections — spliced between sections. */
    part: 'canvas',
    /**
     * A whole page. NOT a drop: dropping one would silently discard the
     * document being edited, so it goes through an explicit import
     * dialog instead.
     */
    page: 'import',
}

/** Where this drag may land. Unknown kinds are refused, not guessed. */
export function placementOf(source: DragSource): Placement | null {
    switch (source.kind) {
        case 'new':
        case 'move':
            return 'column'
        case 'pattern':
        case 'library':
            return ASSET_PLACEMENT[source.assetKind] ?? null
    }
}

/** Whether a drag can be dropped on a target of the given kind. */
export function canDrop(source: DragSource, target: 'column' | 'canvas'): boolean {
    return placementOf(source) === target
}

/** A drag that must go through the import dialog rather than a drop. */
export function needsImportFlow(source: DragSource): boolean {
    return placementOf(source) === 'import'
}

/** Human label for the drag ghost and the announcement. */
export function describeSource(source: DragSource): string {
    switch (source.kind) {
        case 'new':
            return source.handle
        case 'move':
            return 'element'
        case 'pattern':
            return 'pattern'
        case 'library':
            return 'library asset'
    }
}
