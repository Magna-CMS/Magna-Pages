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

/**
 * The field inline canvas editing writes to: the block's first required
 * plain-text field, else its first plain-text field at all. Only `text` and
 * `textarea` qualify — richtext holds markup, and inline editing's safety
 * argument is precisely that it carries plain text only.
 */
export function primaryTextField(definition: BlockDefinition): string | null {
    const textual = definition.fields.filter(
        (field) => field.type === 'text' || field.type === 'textarea',
    )

    return (textual.find((field) => field.required) ?? textual[0])?.handle ?? null
}

export interface LibraryExport {
    name: string
    kind: 'pattern' | 'part' | 'page'
    description: string
    document: unknown
    requiredBlocks: string[]
}

/**
 * Package a selection (or the whole page) as a cloud-library asset — the
 * exact JSON the hub's authoring screen accepts, so publishing is
 * export-here, paste-there with no credential plumbing between a site and
 * the hub.
 *
 * Kind falls out of the selection's structure, matching the library's
 * structural taxonomy: a section exports as a pattern, a block is wrapped
 * into one (an asset must be insertable alone), no selection means the
 * whole page.
 */
export function exportAsLibraryAsset(
    document: BlockDocument,
    nodeId: string | null,
    name: string,
): LibraryExport | null {
    if (nodeId === null) {
        const sections = sectionsOf(document)
        if (sections.length === 0) {
            return null
        }

        return packageExport(name, 'page', sections)
    }

    const found = locate(document, nodeId)
    if (!found || found.kind === 'column') {
        return null
    }

    if (found.kind === 'section') {
        return packageExport(name, 'pattern', found.node)
    }

    return packageExport(name, 'pattern', {
        id: newId(),
        type: 'section',
        settings: {},
        columns: [{ id: newId(), span: 12, settings: {}, blocks: [found.node] }],
    })
}

function packageExport(name: string, kind: LibraryExport['kind'], document: unknown): LibraryExport {
    return {
        name,
        kind,
        description: '',
        document,
        requiredBlocks: blockHandlesIn(document),
    }
}

/** Every block handle in a subtree — mirrors the hub's own computation. */
export function blockHandlesIn(node: unknown): string[] {
    const handles = new Set<string>()

    const walk = (value: unknown): void => {
        if (Array.isArray(value)) {
            value.forEach(walk)

            return
        }
        if (typeof value !== 'object' || value === null) {
            return
        }

        const record = value as Record<string, unknown>
        if (typeof record.block === 'string' && record.block !== '') {
            handles.add(record.block)
        }

        for (const container of ['sections', 'columns', 'blocks', 'children']) {
            if (Array.isArray(record[container])) {
                walk(record[container])
            }
        }
    }

    walk(node)

    return [...handles]
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
