import { describe, expect, it } from 'vitest'

import {
    ancestorsOf,
    blockParents,
    locate,
    newId,
    parentOf,
    subtreeHeight,
    subtreeIds,
} from './locate'
import type { BlockDocument, BlockNode } from './types'

const legacy: BlockDocument = [
    {
        id: 'sec-1',
        type: 'section',
        columns: [
            {
                id: 'col-1',
                span: 12,
                blocks: [
                    { id: 'blk-1', block: 'heading' },
                    { id: 'blk-2', block: 'container', children: [{ id: 'blk-3', block: 'text' }] },
                ],
            },
        ],
    },
]

const wrapped: BlockDocument = { schemaVersion: '1.0', sections: legacy as never }

describe('locate', () => {
    it('points at nodes in a legacy list document', () => {
        expect(locate(legacy, 'sec-1')?.pointer).toBe('/0')
        expect(locate(legacy, 'col-1')?.pointer).toBe('/0/columns/0')
        expect(locate(legacy, 'blk-1')?.pointer).toBe('/0/columns/0/blocks/0')
    })

    it('prefixes pointers with /sections in a wrapped document', () => {
        expect(locate(wrapped, 'sec-1')?.pointer).toBe('/sections/0')
        expect(locate(wrapped, 'blk-1')?.pointer).toBe('/sections/0/columns/0/blocks/0')
    })

    it('finds nested children', () => {
        expect(locate(legacy, 'blk-3')?.pointer).toBe('/0/columns/0/blocks/1/children/0')
    })

    it('reports the kind of node it found', () => {
        expect(locate(legacy, 'col-1')?.kind).toBe('column')
        expect(locate(legacy, 'blk-2')?.kind).toBe('block')
    })

    it('returns null for an unknown id', () => {
        expect(locate(legacy, 'nope')).toBeNull()
    })
})

/** Containers are named by the registry; the tests stand in for it. */
const isContainer = (block: BlockNode): boolean => block.block === 'container'

describe('blockParents', () => {
    it('lists every place a block could go, outermost first', () => {
        expect(blockParents(legacy, isContainer).map((parent) => [parent.id, parent.kind])).toEqual([
            ['col-1', 'column'],
            ['blk-2', 'container'],
        ])
    })

    it('counts depth the way the server does — a column block is depth 1', () => {
        const parents = blockParents(legacy, isContainer)

        expect(parents[0].depth).toBe(1)
        expect(parents[1].depth).toBe(2)
    })

    it('points at the list each parent keeps its blocks in', () => {
        const parents = blockParents(legacy, isContainer)

        expect(parents[0].listPointer).toBe('/0/columns/0/blocks')
        expect(parents[1].listPointer).toBe('/0/columns/0/blocks/1/children')
    })

    it('offers a container that holds nothing yet', () => {
        const empty: BlockDocument = [
            {
                id: 'sec-1',
                type: 'section',
                columns: [{ id: 'col-1', span: 12, blocks: [{ id: 'box', block: 'container' }] }],
            },
        ]

        // A container an editor cannot drop into is a container they
        // cannot use.
        expect(blockParents(empty, isContainer).map((parent) => parent.id)).toEqual(['col-1', 'box'])
    })

    it('never offers a block that does not hold children', () => {
        expect(blockParents(legacy, () => false).map((parent) => parent.id)).toEqual(['col-1'])
    })

    it('prefixes pointers with /sections in a wrapped document', () => {
        expect(blockParents(wrapped, isContainer)[1].listPointer).toBe(
            '/sections/0/columns/0/blocks/1/children',
        )
    })
})

describe('parentOf', () => {
    it('finds the column holding a top-level block', () => {
        expect(parentOf(legacy, 'blk-1')?.id).toBe('col-1')
        expect(parentOf(legacy, 'blk-1')?.kind).toBe('column')
    })

    it('finds the container holding a nested block', () => {
        const parent = parentOf(legacy, 'blk-3')

        expect(parent?.id).toBe('blk-2')
        expect(parent?.kind).toBe('container')
        expect(parent?.depth).toBe(2)
    })

    it('returns null for a node that is not a block', () => {
        expect(parentOf(legacy, 'col-1')).toBeNull()
        expect(parentOf(legacy, 'nope')).toBeNull()
    })
})

describe('subtreeIds', () => {
    it('reports a node and everything under it', () => {
        const box = locate(legacy, 'blk-2')?.node as BlockNode

        expect(subtreeIds(box)).toEqual(['blk-2', 'blk-3'])
    })

    it('reports a leaf as itself', () => {
        expect(subtreeIds(locate(legacy, 'blk-1')?.node)).toEqual(['blk-1'])
    })
})

describe('subtreeHeight', () => {
    it('counts the levels a subtree occupies', () => {
        expect(subtreeHeight(locate(legacy, 'blk-1')?.node as BlockNode)).toBe(1)
        expect(subtreeHeight(locate(legacy, 'blk-2')?.node as BlockNode)).toBe(2)
    })

    it('counts the deepest branch, not the first', () => {
        const deep: BlockNode = {
            id: 'a',
            block: 'container',
            children: [
                { id: 'b', block: 'heading' },
                { id: 'c', block: 'container', children: [{ id: 'd', block: 'heading' }] },
            ],
        }

        expect(subtreeHeight(deep)).toBe(3)
    })
})

describe('newId', () => {
    it('produces 26-character lowercase ids the server will accept', () => {
        const id = newId()

        expect(id).toHaveLength(26)
        expect(id).toMatch(/^[0-9a-hjkmnp-tv-z]{26}$/)
        expect(newId()).not.toBe(id)
    })
})

describe('ancestorsOf', () => {
    /*
     * Selecting a section by clicking is hard: its children cover it, so a
     * click almost always lands on a block. The chain is what lets an
     * editor step back out to what contains what they hit.
     */
    const nested: BlockDocument = [
        {
            id: 'sec-1',
            type: 'section',
            settings: { label: 'Hero row' },
            columns: [
                {
                    id: 'col-1',
                    span: 12,
                    settings: {},
                    blocks: [
                        {
                            id: 'box-1',
                            block: 'container',
                            settings: {},
                            data: {},
                            children: [{ id: 'blk-1', block: 'heading', settings: {}, data: {} }],
                        },
                    ],
                },
            ],
        },
    ]

    it('names every step from the section down to the node', () => {
        expect(ancestorsOf(nested, 'blk-1')).toEqual([
            { id: 'sec-1', kind: 'section', label: 'Hero row' },
            { id: 'col-1', kind: 'column', label: 'Column' },
            { id: 'box-1', kind: 'block', label: 'container' },
            { id: 'blk-1', kind: 'block', label: 'heading' },
        ])
    })

    it('ends at the node itself, whatever kind it is', () => {
        expect(ancestorsOf(nested, 'sec-1')).toHaveLength(1)
        expect(ancestorsOf(nested, 'col-1')).toHaveLength(2)
    })

    it('falls back to the kind when a section has no label of its own', () => {
        const plain: BlockDocument = [
            { id: 'sec-2', type: 'section', settings: {}, columns: [] },
        ]

        expect(ancestorsOf(plain, 'sec-2')[0].label).toBe('Section')
    })

    it('returns nothing for a node the document does not have', () => {
        expect(ancestorsOf(nested, 'gone')).toEqual([])
    })
})
