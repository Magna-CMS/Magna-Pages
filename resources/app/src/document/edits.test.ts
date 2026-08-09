import { describe, expect, it } from 'vitest'

import {
    appendSection,
    blockFrom,
    columnOf,
    emptySection,
    insertBlock,
    moveNode,
    primaryTextField,
    removeNode,
} from './edits'
import { applyPatch } from './patch'
import type { BlockDefinition, BlockDocument, SectionNode } from './types'

const heading: BlockDefinition = {
    handle: 'heading',
    label: 'Heading',
    icon: 'h',
    category: 'content',
    requiresPermission: null,
    fields: [
        {
            handle: 'text',
            type: 'text',
            label: 'Text',
            required: true,
            default: 'New heading',
            options: {},
            multiple: false,
            fields: [],
        },
    ],
}

// Typed as the list form rather than the union so the assertions can index
// into it; the wrapped form has its own test below.
function documentWith(blockIds: string[]): SectionNode[] {
    return [
        {
            id: 'sec-1',
            type: 'section',
            columns: [
                {
                    id: 'col-1',
                    span: 12,
                    blocks: blockIds.map((id) => ({ id, block: 'heading' })),
                },
                { id: 'col-2', span: 12, blocks: [] },
            ],
        },
    ]
}

describe('blockFrom', () => {
    it('seeds a block with its declared defaults and a fresh id', () => {
        const block = blockFrom(heading)

        expect(block.block).toBe('heading')
        expect(block.data).toEqual({ text: 'New heading' })
        expect(block.id).toHaveLength(26)
    })
})

describe('insertBlock', () => {
    it('appends when no index is given', () => {
        const document = documentWith(['a', 'b'])
        const operations = insertBlock(document, 'col-1', blockFrom(heading))

        expect(operations?.[0].path).toBe('/0/columns/0/blocks/-')

        const { document: after } = applyPatch(document, operations ?? [])
        expect(after[0].columns?.[0].blocks).toHaveLength(3)
    })

    it('inserts at a position without displacing its neighbour', () => {
        const document = documentWith(['a', 'c'])
        const operations = insertBlock(document, 'col-1', { id: 'b', block: 'heading' }, 1)

        const { document: after } = applyPatch(document, operations ?? [])
        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['a', 'b', 'c'])
    })

    it('refuses a column that is not in the document', () => {
        expect(insertBlock(documentWith(['a']), 'nope', blockFrom(heading))).toBeNull()
    })
})

describe('moveNode', () => {
    it('accounts for the removal when moving forward in the same column', () => {
        const document = documentWith(['a', 'b', 'c'])

        // Drag "a" to sit where "c" is: after the removal that is index 2.
        const operations = moveNode(document, 'a', 'col-1', 3)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['b', 'c', 'a'])
    })

    it('does not adjust when moving backward in the same column', () => {
        const document = documentWith(['a', 'b', 'c'])

        const operations = moveNode(document, 'c', 'col-1', 0)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['c', 'a', 'b'])
    })

    it('moves a block into another column', () => {
        const document = documentWith(['a', 'b'])

        const operations = moveNode(document, 'a', 'col-2', 0)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['b'])
        expect(after[0].columns?.[1].blocks?.map((b) => b.id)).toEqual(['a'])
    })

    it('is a no-op when the block is already where it is dropped', () => {
        expect(moveNode(documentWith(['a', 'b']), 'a', 'col-1', 0)).toEqual([])
    })
})

describe('removeNode', () => {
    it('removes a block and closes the gap', () => {
        const document = documentWith(['a', 'b', 'c'])
        const { document: after } = applyPatch(document, removeNode(document, 'b') ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['a', 'c'])
    })

    it('returns null for an unknown node', () => {
        expect(removeNode(documentWith(['a']), 'nope')).toBeNull()
    })
})

describe('appendSection', () => {
    it('appends to a legacy list document', () => {
        expect(appendSection(documentWith([]), emptySection())[0].path).toBe('/-')
    })

    it('appends inside the sections key of a wrapped document', () => {
        const wrapped: BlockDocument = { schemaVersion: '1.0', sections: [] }

        expect(appendSection(wrapped, emptySection())[0].path).toBe('/sections/-')
    })
})

describe('primaryTextField', () => {
    function definitionWith(fields: { handle: string; type: string; required: boolean }[]) {
        return {
            ...heading,
            fields: fields.map((field) => ({
                ...heading.fields[0],
                ...field,
            })),
        }
    }

    it('prefers the first required plain-text field', () => {
        const definition = definitionWith([
            { handle: 'eyebrow', type: 'text', required: false },
            { handle: 'title', type: 'text', required: true },
        ])

        expect(primaryTextField(definition)).toBe('title')
    })

    it('falls back to any plain-text field, skipping richtext', () => {
        const definition = definitionWith([
            { handle: 'body', type: 'richtext', required: true },
            { handle: 'caption', type: 'textarea', required: false },
        ])

        expect(primaryTextField(definition)).toBe('caption')
    })

    it('returns null for a block with no plain-text field', () => {
        const definition = definitionWith([{ handle: 'image', type: 'media', required: true }])

        expect(primaryTextField(definition)).toBeNull()
    })
})

describe('columnOf', () => {
    it('finds the column holding a block', () => {
        expect(columnOf(documentWith(['a']), 'a')).toBe('col-1')
        expect(columnOf(documentWith(['a']), 'nope')).toBeNull()
    })
})
