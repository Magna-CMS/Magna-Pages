import { describe, expect, it } from 'vitest'

import type { NodeRect } from './bridge'
import { dropTargetAt, exceedsThreshold, layout, sectionDropTargetAt, sectionLayout } from './dragdrop'

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

describe('sectionDropTargetAt', () => {
    const sections = sectionLayout(
        [
            rect('sec-1', 'section', 0, 200),
            rect('sec-2', 'section', 200, 200),
        ],
        ['sec-1', 'sec-2'],
    )

    it('drops before a section when above its midpoint', () => {
        expect(sectionDropTargetAt(sections, 50, 600)).toMatchObject({ index: 0 })
    })

    it('drops between sections', () => {
        expect(sectionDropTargetAt(sections, 250, 600)).toMatchObject({ index: 1 })
    })

    it('appends below the last section rather than refusing', () => {
        // The area under the final section is where people aim for "at the
        // end"; returning null there would read as broken.
        expect(sectionDropTargetAt(sections, 900, 600)).toMatchObject({ index: 2 })
        expect(sectionDropTargetAt(sections, 900, 600)?.indicator.top).toBe(400)
    })

    it('accepts the first drop on an empty page', () => {
        expect(sectionDropTargetAt([], 10, 600)).toEqual({
            index: 0,
            indicator: { top: 0, left: 0, width: 600 },
        })
    })

    it('orders sections by the document, not by geometry', () => {
        const ordered = sectionLayout(
            [rect('sec-b', 'section', 0, 100), rect('sec-a', 'section', 100, 100)],
            ['sec-a', 'sec-b'],
        )

        expect(ordered.map((entry) => entry.node)).toEqual(['sec-a', 'sec-b'])
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
