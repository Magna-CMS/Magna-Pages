import { describe, expect, it } from 'vitest'

import type { NodeRect } from './bridge'
import { dropTargetAt, exceedsThreshold, layout } from './dragdrop'

function rect(node: string, kind: NodeRect['kind'], top: number, height: number, left = 0, width = 600): NodeRect {
    return { node, kind, top, left, width, height }
}

const rects: NodeRect[] = [
    rect('col-1', 'column', 0, 300, 0, 300),
    rect('col-2', 'column', 0, 300, 300, 300),
    rect('blk-a', 'block', 0, 100, 0, 300),
    rect('blk-b', 'block', 100, 100, 0, 300),
]

const columns = layout(rects, { 'col-1': ['blk-a', 'blk-b'], 'col-2': [] })

describe('layout', () => {
    it('groups blocks under their column in document order', () => {
        expect(columns.map((entry) => entry.column)).toEqual(['col-1', 'col-2'])
        expect(columns[0].blocks.map((block) => block.node)).toEqual(['blk-a', 'blk-b'])
        expect(columns[1].blocks).toEqual([])
    })

    it('keeps document order even when blocks share a vertical position', () => {
        const sideBySide = layout(
            [
                rect('col-1', 'column', 0, 100),
                rect('blk-second', 'block', 0, 50, 300, 300),
                rect('blk-first', 'block', 0, 50, 0, 300),
            ],
            { 'col-1': ['blk-first', 'blk-second'] },
        )

        expect(sideBySide[0].blocks.map((block) => block.node)).toEqual(['blk-first', 'blk-second'])
    })
})

describe('dropTargetAt', () => {
    it('drops before a block when above its midpoint', () => {
        expect(dropTargetAt(columns, 100, 40)).toMatchObject({ column: 'col-1', index: 0 })
    })

    it('drops after a block when below its midpoint', () => {
        expect(dropTargetAt(columns, 100, 60)).toMatchObject({ column: 'col-1', index: 1 })
    })

    it('drops at the end when below every block', () => {
        expect(dropTargetAt(columns, 100, 280)).toMatchObject({ column: 'col-1', index: 2 })
    })

    it('drops into an empty column, which a nearest-block search would skip', () => {
        expect(dropTargetAt(columns, 400, 150)).toMatchObject({ column: 'col-2', index: 0 })
    })

    it('returns null outside every column', () => {
        expect(dropTargetAt(columns, 900, 150)).toBeNull()
    })

    it('draws the indicator where the block would land', () => {
        expect(dropTargetAt(columns, 100, 60)?.indicator).toEqual({ top: 100, left: 0, width: 300 })
        expect(dropTargetAt(columns, 100, 280)?.indicator).toEqual({ top: 200, left: 0, width: 300 })
    })
})

describe('exceedsThreshold', () => {
    it('ignores the jitter of a click', () => {
        expect(exceedsThreshold({ x: 10, y: 10 }, { x: 12, y: 11 })).toBe(false)
    })

    it('reports a real drag', () => {
        expect(exceedsThreshold({ x: 10, y: 10 }, { x: 10, y: 30 })).toBe(true)
    })
})
