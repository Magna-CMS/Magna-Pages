import { locate, newId } from './locate'
import type {
    BlockDefinition,
    BlockDocument,
    BlockNode,
    ColumnNode,
    PatchOperation,
    SectionNode,
} from './types'
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

/**
 * A block seeded with its definition's declared defaults — plus a
 * placeholder for any REQUIRED text field without one, because a fresh
 * block must be insertable: the server validates required fields on every
 * save, and "empty data" would make any block with a defaultless required
 * text field (the core heading, for one) silently refuse insertion.
 */
export function blockFrom(definition: BlockDefinition): BlockNode {
    const data: Record<string, unknown> = {}

    for (const field of definition.fields) {
        if (field.default !== null && field.default !== undefined) {
            data[field.handle] = field.default
        } else if (
            field.required &&
            (field.type === 'text' || field.type === 'textarea' || field.type === 'richtext')
        ) {
            data[field.handle] = field.label
        }
    }

    return { id: newId(), block: definition.handle, settings: {}, data }
}

/**
 * The column structures the Add-Section picker offers, as span lists over
 * the 12-column grid the format already uses. Presets rather than free
 * numbers because "choose a layout" is the decision an editor is making;
 * arbitrary spans are available afterwards on the section itself.
 */
export const COLUMN_PRESETS: { label: string; spans: number[] }[] = [
    { label: '1 column', spans: [12] },
    { label: '50 / 50', spans: [6, 6] },
    { label: '33 / 33 / 33', spans: [4, 4, 4] },
    { label: '25 × 4', spans: [3, 3, 3, 3] },
    { label: '66 / 33', spans: [8, 4] },
    { label: '33 / 66', spans: [4, 8] },
    { label: '25 / 75', spans: [3, 9] },
    { label: '75 / 25', spans: [9, 3] },
]

export function emptyColumn(span: number): ColumnNode {
    return { id: newId(), span: clampSpan(span), settings: {}, blocks: [] }
}

/**
 * An empty section with the given column structure. Defaults to one
 * full-width column — the shape every page started from before the
 * structure picker existed, so existing callers are unchanged.
 */
export function emptySection(spans: number[] = [12]): SectionNode {
    const columns = (spans.length > 0 ? spans : [12]).map(emptyColumn)

    return { id: newId(), type: 'section', settings: {}, columns }
}

export function appendSection(document: BlockDocument, section: SectionNode): PatchOperation[] {
    const prefix = sectionsPointer(document)

    return [{ op: 'add', path: `${prefix}/-`, value: section }]
}

/**
 * Insert sections at a position. `index` beyond the end appends, which is
 * what a drop below the last section means.
 */
export function insertSectionsAt(
    document: BlockDocument,
    sections: SectionNode[],
    index: number,
): PatchOperation[] {
    const prefix = sectionsPointer(document)
    const total = sectionsOf(document).length
    const at = Math.max(0, Math.min(total, index))

    // Later sections insert after earlier ones, so each subsequent op
    // targets one position further along.
    return sections.map((section, offset) => ({
        op: 'add' as const,
        path: `${prefix}/${at + offset}`,
        value: section,
    }))
}

/** Move a section to another position in the document's section list. */
export function moveSection(
    document: BlockDocument,
    sectionId: string,
    index: number,
): PatchOperation[] | null {
    const source = locate(document, sectionId)
    if (!source || source.kind !== 'section') {
        return null
    }

    const prefix = sectionsPointer(document)
    const from = Number(source.pointer.slice(prefix.length + 1).split('/')[0])
    if (Number.isNaN(from)) {
        return null
    }

    // Same remove-then-add arithmetic as a block move: a section travelling
    // forward lands one short unless the removal is accounted for.
    const destination = from < index ? index - 1 : index
    if (destination === from) {
        return []
    }

    return [{ op: 'move', from: source.pointer, path: `${prefix}/${destination}` }]
}

/** Add a column to a section, splitting the room evenly. */
export function addColumn(document: BlockDocument, sectionId: string): PatchOperation[] | null {
    const found = locate(document, sectionId)
    if (!found || found.kind !== 'section') {
        return null
    }

    const columns = ((found.node as SectionNode).columns ?? []).length
    if (columns >= 12) {
        return null
    }

    const spans = evenSpans(columns + 1)

    return [
        { op: 'add', path: `${found.pointer}/columns/-`, value: emptyColumn(spans[columns]) },
        ...respan(found.pointer, spans.slice(0, columns)),
    ]
}

/**
 * Remove a column and give its room back to the others.
 *
 * Refuses the last column: a section with no columns can hold nothing and
 * renders as an empty band, which reads as a bug rather than a choice.
 */
export function removeColumn(
    document: BlockDocument,
    sectionId: string,
    columnId: string,
): PatchOperation[] | null {
    const section = locate(document, sectionId)
    const column = locate(document, columnId)
    if (!section || section.kind !== 'section' || !column || column.kind !== 'column') {
        return null
    }

    const columns = (section.node as SectionNode).columns ?? []
    if (columns.length <= 1) {
        return null
    }

    const remaining = columns.filter((entry) => entry.id !== columnId)

    return [
        { op: 'remove', path: column.pointer },
        ...respan(section.pointer, evenSpans(remaining.length)),
    ]
}

/** Set explicit spans on a section's columns, clamped and summing to 12. */
export function setSpans(
    document: BlockDocument,
    sectionId: string,
    spans: number[],
): PatchOperation[] | null {
    const found = locate(document, sectionId)
    if (!found || found.kind !== 'section') {
        return null
    }

    const columns = ((found.node as SectionNode).columns ?? []).length
    if (columns === 0 || spans.length !== columns) {
        return null
    }

    return respan(found.pointer, balance(spans))
}

/** A deep copy of a node with every id replaced — the paste/duplicate primitive. */
export function withFreshIds<T>(node: T): T {
    if (Array.isArray(node)) {
        return node.map((entry) => withFreshIds(entry)) as unknown as T
    }

    if (node !== null && typeof node === 'object') {
        const copy: Record<string, unknown> = {}
        for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
            copy[key] = key === 'id' && typeof value === 'string' ? newId() : withFreshIds(value)
        }

        return copy as unknown as T
    }

    return node
}

/**
 * Duplicate any node next to itself. Fresh ids throughout: two nodes
 * sharing an id would make every pointer ambiguous.
 */
export function duplicateNode(document: BlockDocument, nodeId: string): PatchOperation[] | null {
    const found = locate(document, nodeId)
    if (!found) {
        return null
    }

    const pointer = found.pointer
    const separator = pointer.lastIndexOf('/')
    const index = Number(pointer.slice(separator + 1))
    if (Number.isNaN(index)) {
        return null
    }

    return [
        {
            op: 'add',
            path: `${pointer.slice(0, separator)}/${index + 1}`,
            value: withFreshIds(found.node),
        },
    ]
}

function respan(sectionPointer: string, spans: number[]): PatchOperation[] {
    return spans.map((span, index) => ({
        op: 'replace' as const,
        path: `${sectionPointer}/columns/${index}/span`,
        value: span,
    }))
}

function clampSpan(span: number): number {
    return Math.max(1, Math.min(12, Math.round(Number.isFinite(span) ? span : 12)))
}

/** Twelve columns split as evenly as they divide, remainder to the left. */
function evenSpans(count: number): number[] {
    const safe = Math.max(1, Math.min(12, count))
    const base = Math.floor(12 / safe)
    const spans = Array.from({ length: safe }, () => base)

    for (let i = 0; i < 12 - base * safe; i++) {
        spans[i] += 1
    }

    return spans
}

/**
 * Clamp each span and make the row sum to 12 — a row that sums to
 * anything else lays out wrong, so the UI is never allowed to write one.
 */
function balance(spans: number[]): number[] {
    const clamped = spans.map(clampSpan)
    let total = clamped.reduce((sum, span) => sum + span, 0)

    for (let i = clamped.length - 1; i >= 0 && total !== 12; i--) {
        const room = total > 12 ? -(Math.min(total - 12, clamped[i] - 1)) : 12 - total
        clamped[i] = clampSpan(clamped[i] + room)
        total = clamped.reduce((sum, span) => sum + span, 0)
    }

    return clamped
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
