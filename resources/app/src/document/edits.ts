import { locate, newId } from './locate'
import type { BlockDefinition, BlockDocument, BlockNode, PatchOperation, SectionNode } from './types'
import { sectionsOf, sectionsPointer } from './types'

/**
 * Turning a user gesture into the patch operations that express it.
 *
 * Kept apart from the store so a gesture is a pure function of the document
 * — the same input always yields the same operations, which is what makes
 * them safe to replay for undo and cheap to test without a running app.
 *
 * Every new node gets its id here, client-side, so the optimistic render and
 * the saved document agree on what to call it. A server-assigned id would
 * mean the canvas could not address the node it just inserted until the
 * round trip finished.
 */

/** A block seeded with its definition's declared defaults. */
export function blockFrom(definition: BlockDefinition): BlockNode {
    const data: Record<string, unknown> = {}

    for (const field of definition.fields) {
        if (field.default !== null && field.default !== undefined) {
            data[field.handle] = field.default
        }
    }

    return { id: newId(), block: definition.handle, settings: {}, data }
}

/** An empty full-width section, the scaffold every page starts from. */
export function emptySection(): SectionNode {
    return {
        id: newId(),
        type: 'section',
        settings: {},
        columns: [{ id: newId(), span: 12, settings: {}, blocks: [] }],
    }
}

export function appendSection(document: BlockDocument, section: SectionNode): PatchOperation[] {
    const prefix = sectionsPointer(document)

    return [{ op: 'add', path: `${prefix}/-`, value: section }]
}

/**
 * Insert a block into a column. `index` is the position it should end up at;
 * omitting it appends.
 */
export function insertBlock(
    document: BlockDocument,
    columnId: string,
    block: BlockNode,
    index?: number,
): PatchOperation[] | null {
    const column = locate(document, columnId)
    if (!column || column.kind !== 'column') {
        return null
    }

    const at = index === undefined ? '-' : String(index)

    return [{ op: 'add', path: `${column.pointer}/blocks/${at}`, value: block }]
}

export function removeNode(document: BlockDocument, nodeId: string): PatchOperation[] | null {
    const found = locate(document, nodeId)
    if (!found) {
        return null
    }

    return [{ op: 'remove', path: found.pointer }]
}

/**
 * Move a node to a new position.
 *
 * The destination pointer is computed against the document as it is BEFORE
 * the move, which is what the server does too: a move is remove-then-add,
 * and an index that ignores the removal lands one position off whenever the
 * node travels forward inside the same list.
 */
export function moveNode(
    document: BlockDocument,
    nodeId: string,
    targetColumnId: string,
    index: number,
): PatchOperation[] | null {
    const source = locate(document, nodeId)
    const column = locate(document, targetColumnId)

    if (!source || source.kind !== 'block' || !column || column.kind !== 'column') {
        return null
    }

    const destination = `${column.pointer}/blocks/${adjustedIndex(source.pointer, column.pointer, index)}`

    if (destination === source.pointer) {
        return []
    }

    return [{ op: 'move', from: source.pointer, path: destination }]
}

/**
 * When a block moves forward within its own column, the removal shifts every
 * later sibling down one — so the caller's "drop at index 3" is index 2 by
 * the time the add runs.
 */
function adjustedIndex(sourcePointer: string, columnPointer: string, index: number): number {
    const prefix = `${columnPointer}/blocks/`
    if (!sourcePointer.startsWith(prefix)) {
        return index
    }

    const sourceIndex = Number(sourcePointer.slice(prefix.length).split('/')[0])

    return Number.isNaN(sourceIndex) || sourceIndex >= index ? index : index - 1
}

/** The column a block currently sits in, for drag bookkeeping. */
export function columnOf(document: BlockDocument, blockId: string): string | null {
    for (const section of sectionsOf(document)) {
        for (const column of section.columns ?? []) {
            if ((column.blocks ?? []).some((block) => block.id === blockId)) {
                return column.id
            }
        }
    }

    return null
}
