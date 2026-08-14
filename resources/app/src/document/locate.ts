import type { BlockDocument, BlockNode, SectionNode } from './types'
import { sectionsOf, sectionsPointer } from './types'

/**
 * Finding a node in the document and building the JSON Pointer that
 * addresses it.
 *
 * The canvas talks in node ids (that is what a click yields); the patch API
 * talks in pointers. This is the one place that translation happens, so a
 * pointer can never be assembled by string concatenation at a call site and
 * drift from the document's real shape — including the legacy-vs-wrapped
 * distinction, which changes the prefix of every pointer in the document.
 */

/** What a located node is. Named so tables about nodes can speak of it. */
export type NodeKind = 'section' | 'column' | 'block'

export interface Located {
    kind: NodeKind
    pointer: string
    node: SectionNode | BlockNode | Record<string, unknown>
}

export function locate(document: BlockDocument, nodeId: string): Located | null {
    const prefix = sectionsPointer(document)
    const sections = sectionsOf(document)

    for (let s = 0; s < sections.length; s++) {
        const section = sections[s]
        const sectionPointer = `${prefix}/${s}`

        if (section.id === nodeId) {
            return { kind: 'section', pointer: sectionPointer, node: section }
        }

        const columns = section.columns ?? []
        for (let c = 0; c < columns.length; c++) {
            const column = columns[c]
            const columnPointer = `${sectionPointer}/columns/${c}`

            if (column.id === nodeId) {
                return { kind: 'column', pointer: columnPointer, node: column }
            }

            const found = locateBlock(column.blocks ?? [], nodeId, `${columnPointer}/blocks`)
            if (found) {
                return found
            }
        }
    }

    return null
}

/**
 * A place a block can live: a column's `blocks`, or a container block's
 * `children`.
 *
 * The two are the same thing to everything above this line — a drop, a
 * paste, a move, a navigator row. Naming them one type is what stops the
 * builder growing a second set of insert/move producers for nesting, which
 * would then have to be kept in step with the first one forever.
 */
export interface BlockParent {
    /** The holder's node id: a column id, or a container block's id. */
    id: string
    kind: 'column' | 'container'
    /** Pointer to the holder node itself. */
    pointer: string
    /** Pointer to the list the holder keeps its blocks in. */
    listPointer: string
    blocks: BlockNode[]
    /**
     * The depth blocks placed here occupy. A column's blocks are depth 1,
     * matching PageTreeValidator's count, so a limit shipped by the server
     * means the same number on both sides.
     */
    depth: number
}

/** Whether a block holds other blocks. Answered by the registry, never guessed. */
export type IsContainer = (block: BlockNode) => boolean

/**
 * Every place a block could be put, in document order, outermost first.
 *
 * A container that holds nothing yet still appears — an empty container the
 * editor cannot drop into is a container they cannot use.
 */
export function blockParents(document: BlockDocument, isContainer: IsContainer): BlockParent[] {
    const prefix = sectionsPointer(document)
    const parents: BlockParent[] = []

    sectionsOf(document).forEach((section, s) => {
        ;(section.columns ?? []).forEach((column, c) => {
            const pointer = `${prefix}/${s}/columns/${c}`
            const blocks = column.blocks ?? []
            parents.push({
                id: column.id,
                kind: 'column',
                pointer,
                listPointer: `${pointer}/blocks`,
                blocks,
                depth: 1,
            })

            collectContainers(blocks, `${pointer}/blocks`, 1, isContainer, parents)
        })
    })

    return parents
}

function collectContainers(
    blocks: BlockNode[],
    listPointer: string,
    depth: number,
    isContainer: IsContainer,
    into: BlockParent[],
): void {
    blocks.forEach((block, index) => {
        if (!isContainer(block)) {
            return
        }

        const pointer = `${listPointer}/${index}`
        const children = block.children ?? []
        into.push({
            id: block.id,
            kind: 'container',
            pointer,
            listPointer: `${pointer}/children`,
            blocks: children,
            depth: depth + 1,
        })

        collectContainers(children, `${pointer}/children`, depth + 1, isContainer, into)
    })
}

/**
 * The parent that currently holds a block.
 *
 * No registry needed here: a block sitting in a `children` list is inside a
 * container by construction, whatever its definition claims today.
 */
export function parentOf(document: BlockDocument, blockId: string): BlockParent | null {
    const prefix = sectionsPointer(document)
    const sections = sectionsOf(document)

    for (let s = 0; s < sections.length; s++) {
        const columns = sections[s].columns ?? []
        for (let c = 0; c < columns.length; c++) {
            const pointer = `${prefix}/${s}/columns/${c}`
            const found = searchParent(
                { id: columns[c].id, kind: 'column', pointer, listPointer: `${pointer}/blocks`, blocks: columns[c].blocks ?? [], depth: 1 },
                blockId,
            )
            if (found) {
                return found
            }
        }
    }

    return null
}

function searchParent(parent: BlockParent, blockId: string): BlockParent | null {
    for (let index = 0; index < parent.blocks.length; index++) {
        const block = parent.blocks[index]
        if (block.id === blockId) {
            return parent
        }

        const pointer = `${parent.listPointer}/${index}`
        const found = searchParent(
            {
                id: block.id,
                kind: 'container',
                pointer,
                listPointer: `${pointer}/children`,
                blocks: block.children ?? [],
                depth: parent.depth + 1,
            },
            blockId,
        )
        if (found) {
            return found
        }
    }

    return null
}

/** Every node id inside a subtree, the subtree's own root included. */
export function subtreeIds(node: unknown): string[] {
    const ids: string[] = []

    const walk = (value: unknown): void => {
        if (Array.isArray(value)) {
            value.forEach(walk)

            return
        }
        if (typeof value !== 'object' || value === null) {
            return
        }

        const record = value as Record<string, unknown>
        if (typeof record.id === 'string' && record.id !== '') {
            ids.push(record.id)
        }

        for (const container of ['sections', 'columns', 'blocks', 'children']) {
            if (Array.isArray(record[container])) {
                walk(record[container])
            }
        }
    }

    walk(node)

    return ids
}

/**
 * How many levels of block a subtree occupies — a leaf is 1, a container
 * holding a leaf is 2. What a depth check needs: moving a subtree into a
 * parent costs `parent.depth + height - 1`.
 */
export function subtreeHeight(block: BlockNode): number {
    const children = block.children ?? []

    return children.length === 0 ? 1 : 1 + Math.max(...children.map(subtreeHeight))
}

function locateBlock(blocks: BlockNode[], nodeId: string, prefix: string): Located | null {
    for (let b = 0; b < blocks.length; b++) {
        const block = blocks[b]
        const pointer = `${prefix}/${b}`

        if (block.id === nodeId) {
            return { kind: 'block', pointer, node: block }
        }

        const found = locateBlock(block.children ?? [], nodeId, `${pointer}/children`)
        if (found) {
            return found
        }
    }

    return null
}

/** A ULID-shaped id, so client-created nodes match what the server expects. */
export function newId(): string {
    const alphabet = '0123456789abcdefghjkmnpqrstvwxyz'
    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)

    const time = Date.now()
    let id = ''
    for (let i = 9; i >= 0; i--) {
        id = alphabet[Math.floor(time / 32 ** (9 - i)) % 32] + id
    }
    for (let i = 0; i < 16; i++) {
        id += alphabet[bytes[i] % 32]
    }

    return id
}
