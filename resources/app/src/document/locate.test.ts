import { describe, expect, it } from 'vitest'

import { locate, newId } from './locate'
import type { BlockDocument } from './types'

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

describe('newId', () => {
    it('produces 26-character lowercase ids the server will accept', () => {
        const id = newId()

        expect(id).toHaveLength(26)
        expect(id).toMatch(/^[0-9a-hjkmnp-tv-z]{26}$/)
        expect(newId()).not.toBe(id)
    })
})
