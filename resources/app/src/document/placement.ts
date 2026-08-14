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

/**
 * Where a payload is allowed to land.
 *
 * `blocks` covers BOTH places a block can go — a column (sibling
 * insertion) and a container block's children (container insertion) —
 * because a block that may live in one may live in the other; the
 * difference is where the drop lands, not whether it is allowed. `canvas`
 * is root insertion, between sections.
 */
export type Placement = 'blocks' | 'canvas' | 'import'

/** The two kinds of holder a `blocks` payload can land in. */
export type BlockParentKind = 'column' | 'container'

/** Every place a drop can be aimed at. */
export type DropTargetKind = BlockParentKind | 'canvas'

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
    /** One block node — belongs inside a column or a container. */
    block: 'blocks',
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
            return 'blocks'
        case 'pattern':
        case 'library':
            return ASSET_PLACEMENT[source.assetKind] ?? null
    }
}

/** Whether a drag can be dropped on a target of the given kind. */
export function canDrop(source: DragSource, target: DropTargetKind): boolean {
    const placement = placementOf(source)

    return placement === 'blocks' ? target !== 'canvas' : placement === target
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
