import { describe, expect, it } from 'vitest'

import {
    COLUMN_PRESETS,
    addColumn,
    duplicateNode,
    emptySection,
    insertSectionsAt,
    moveSection,
    removeColumn,
    removeNode,
    sectionOf,
    setSpans,
    withFreshIds,
} from './edits'
import { applyPatch } from './patch'
import type { BlockDocument, SectionNode } from './types'

/** Two sections, the first with a block, so moves and duplicates have something to bite on. */
function doc(): SectionNode[] {
    return [
        {
            id: 'sec-1',
            type: 'section',
            settings: {},
            columns: [
                {
                    id: 'col-1',
                    span: 12,
                    settings: {},
                    blocks: [{ id: 'blk-1', block: 'heading', settings: {}, data: { text: 'Hi' } }],
                },
            ],
        },
        { id: 'sec-2', type: 'section', settings: {}, columns: [{ id: 'col-2', span: 12, settings: {}, blocks: [] }] },
    ]
}

function apply(document: BlockDocument, ops: ReturnType<typeof addColumn>): BlockDocument {
    return applyPatch(document, ops ?? []).document
}

describe('emptySection', () => {
    it('defaults to one full-width column', () => {
        const section = emptySection()

        expect(section.columns).toHaveLength(1)
        expect(section.columns?.[0].span).toBe(12)
    })

    it('builds the picked structure', () => {
        expect(emptySection([6, 6]).columns?.map((c) => c.span)).toEqual([6, 6])
        expect(emptySection([4, 4, 4]).columns?.map((c) => c.span)).toEqual([4, 4, 4])
    })

    it('gives every column and the section fresh ids', () => {
        const a = emptySection([6, 6])
        const b = emptySection([6, 6])

        expect(a.id).not.toBe(b.id)
        expect(a.columns?.[0].id).not.toBe(a.columns?.[1].id)
    })

    it('offers presets that all sum to twelve', () => {
        for (const preset of COLUMN_PRESETS) {
            expect(preset.spans.reduce((sum, span) => sum + span, 0)).toBe(12)
        }
    })
})

describe('insertSectionsAt', () => {
    it('inserts at a position', () => {
        const result = apply(doc(), insertSectionsAt(doc(), [emptySection()], 1))

        expect((result as SectionNode[]).map((s) => s.id)[0]).toBe('sec-1')
        expect((result as SectionNode[])).toHaveLength(3)
        expect((result as SectionNode[])[2].id).toBe('sec-2')
    })

    it('keeps a multi-section asset in order', () => {
        const a = emptySection()
        const b = emptySection()
        const result = apply(doc(), insertSectionsAt(doc(), [a, b], 0)) as SectionNode[]

        expect(result.map((s) => s.id)).toEqual([a.id, b.id, 'sec-1', 'sec-2'])
    })

    it('clamps past the end to an append', () => {
        const result = apply(doc(), insertSectionsAt(doc(), [emptySection()], 99)) as SectionNode[]

        expect(result).toHaveLength(3)
        expect(result[0].id).toBe('sec-1')
    })
})

describe('moveSection', () => {
    it('reorders sections', () => {
        const result = apply(doc(), moveSection(doc(), 'sec-2', 0)) as SectionNode[]

        expect(result.map((s) => s.id)).toEqual(['sec-2', 'sec-1'])
    })

    it('accounts for the removal when travelling forward', () => {
        const result = apply(doc(), moveSection(doc(), 'sec-1', 2)) as SectionNode[]

        expect(result.map((s) => s.id)).toEqual(['sec-2', 'sec-1'])
    })

    it('is a no-op when the position does not change', () => {
        expect(moveSection(doc(), 'sec-1', 0)).toEqual([])
    })

    it('refuses a node that is not a section', () => {
        expect(moveSection(doc(), 'blk-1', 0)).toBeNull()
    })
})

describe('columns', () => {
    it('adds a column and rebalances the row to twelve', () => {
        const result = apply(doc(), addColumn(doc(), 'sec-1')) as SectionNode[]
        const spans = result[0].columns?.map((c) => c.span) ?? []

        expect(spans).toHaveLength(2)
        expect(spans.reduce((sum, span) => sum + span, 0)).toBe(12)
    })

    it('removes a column and gives its room back', () => {
        const three = apply(
            apply(doc(), addColumn(doc(), 'sec-1')),
            addColumn(apply(doc(), addColumn(doc(), 'sec-1')), 'sec-1'),
        ) as SectionNode[]
        const target = three[0].columns?.[2].id ?? ''

        const result = apply(three, removeColumn(three, 'sec-1', target)) as SectionNode[]
        const spans = result[0].columns?.map((c) => c.span) ?? []

        expect(spans).toHaveLength(2)
        expect(spans.reduce((sum, span) => sum + span, 0)).toBe(12)
    })

    it('refuses to remove the last column', () => {
        // A section with no columns holds nothing and renders as an empty
        // band — that reads as a bug, not a choice.
        expect(removeColumn(doc(), 'sec-1', 'col-1')).toBeNull()
    })

    it('balances explicit spans that do not sum to twelve', () => {
        const two = apply(doc(), addColumn(doc(), 'sec-1')) as SectionNode[]
        const result = apply(two, setSpans(two, 'sec-1', [9, 9])) as SectionNode[]
        const spans = result[0].columns?.map((c) => c.span) ?? []

        expect(spans.reduce((sum, span) => sum + span, 0)).toBe(12)
        expect(spans.every((span) => span >= 1)).toBe(true)
    })

    it('refuses a span list that does not match the columns', () => {
        expect(setSpans(doc(), 'sec-1', [6, 6])).toBeNull()
    })
})

describe('removeNode on columns', () => {
    it('takes the whole section when the column was its last', () => {
        const result = apply(doc(), removeNode(doc(), 'col-1')) as SectionNode[]

        expect(result.map((s) => s.id)).toEqual(['sec-2'])
    })

    it('takes only the column when the row has others', () => {
        const two = apply(doc(), addColumn(doc(), 'sec-1')) as SectionNode[]
        const extra = two[0].columns?.[1].id ?? ''

        const result = apply(two, removeNode(two, extra)) as SectionNode[]

        expect(result).toHaveLength(2)
        expect(result[0].columns).toHaveLength(1)
    })
})

describe('sectionOf', () => {
    it('finds the row a column belongs to', () => {
        expect(sectionOf(doc(), 'col-2')?.id).toBe('sec-2')
    })

    it('refuses anything that is not a column of this document', () => {
        expect(sectionOf(doc(), 'blk-1')).toBeNull()
        expect(sectionOf(doc(), 'sec-1')).toBeNull()
        expect(sectionOf(doc(), 'nope')).toBeNull()
    })
})

describe('duplicateNode', () => {
    it('places a copy next to a block with fresh ids', () => {
        const result = apply(doc(), duplicateNode(doc(), 'blk-1')) as SectionNode[]
        const blocks = result[0].columns?.[0].blocks ?? []

        expect(blocks).toHaveLength(2)
        expect(blocks[1].id).not.toBe('blk-1')
        expect(blocks[1].data).toEqual({ text: 'Hi' })
    })

    it('places a copy next to a section, nested ids all fresh', () => {
        const result = apply(doc(), duplicateNode(doc(), 'sec-1')) as SectionNode[]

        expect(result).toHaveLength(3)
        expect(result[1].id).not.toBe('sec-1')
        expect(result[1].columns?.[0].id).not.toBe('col-1')
        expect(result[1].columns?.[0].blocks?.[0].id).not.toBe('blk-1')
    })

    it('refuses an unknown node', () => {
        expect(duplicateNode(doc(), 'nope')).toBeNull()
    })
})

describe('withFreshIds', () => {
    it('replaces every id and leaves everything else alone', () => {
        const copy = withFreshIds({
            id: 'a',
            keep: 'value',
            nested: { id: 'b', list: [{ id: 'c' }] },
        })

        expect(copy.id).not.toBe('a')
        expect(copy.keep).toBe('value')
        expect(copy.nested.id).not.toBe('b')
        expect(copy.nested.list[0].id).not.toBe('c')
    })

    it('preserves unknown keys, as the tolerant reader promises', () => {
        const copy = withFreshIds({ id: 'a', futureThing: { deep: true } })

        expect(copy.futureThing).toEqual({ deep: true })
    })
})
