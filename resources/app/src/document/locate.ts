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
