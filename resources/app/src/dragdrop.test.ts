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

const columns = layout(rects, [
    { id: 'col-1', kind: 'column', depth: 1, blockIds: ['blk-a', 'blk-b'] },
    { id: 'col-2', kind: 'column', depth: 1, blockIds: [] },
])

/**
 * The same column with a container in its lower half — the shape every
 * nesting rule is argued over: a box inside a box, both under the pointer.
 */
const nestedRects: NodeRect[] = [
    rect('col-1', 'column', 0, 400, 0, 300),
    rect('blk-a', 'block', 0, 100, 0, 300),
    rect('box-1', 'block', 200, 200, 0, 300),
    rect('blk-c', 'block', 200, 100, 0, 300),
    rect('box-2', 'block', 300, 100, 0, 300),
]

const nested = layout(nestedRects, [
    { id: 'col-1', kind: 'column', depth: 1, blockIds: ['blk-a', 'box-1'] },
    { id: 'box-1', kind: 'container', depth: 2, blockIds: ['blk-c', 'box-2'] },
    { id: 'box-2', kind: 'container', depth: 3, blockIds: [] },
])

describe('layout', () => {
    it('groups blocks under their parent in document order', () => {
        expect(columns.map((entry) => entry.parent)).toEqual(['col-1', 'col-2'])
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
            [{ id: 'col-1', kind: 'column', depth: 1, blockIds: ['blk-first', 'blk-second'] }],
        )

        expect(sideBySide[0].blocks.map((block) => block.node)).toEqual(['blk-first', 'blk-second'])
    })

    it('drops a parent the canvas has not reported a rect for', () => {
        // A container inside a collapsed/conditioned branch has no box; a
        // layout entry with no rect would crash the geometry search.
        expect(layout(rects, [{ id: 'ghost', kind: 'container', depth: 2, blockIds: [] }])).toEqual([])
    })
})

describe('dropTargetAt', () => {
    it('drops before a block when above its midpoint', () => {
        expect(dropTargetAt(columns, 100, 40)).toMatchObject({ parent: 'col-1', index: 0 })
    })

    it('drops after a block when below its midpoint', () => {
        expect(dropTargetAt(columns, 100, 60)).toMatchObject({ parent: 'col-1', index: 1 })
    })

    it('drops at the end when below every block', () => {
        expect(dropTargetAt(columns, 100, 280)).toMatchObject({ parent: 'col-1', index: 2 })
    })

    it('drops into an empty column, which a nearest-block search would skip', () => {
        expect(dropTargetAt(columns, 400, 150)).toMatchObject({ parent: 'col-2', index: 0 })
    })

    it('returns null outside every column', () => {
        expect(dropTargetAt(columns, 900, 150)).toBeNull()
    })

    it('draws the indicator where the block would land', () => {
        expect(dropTargetAt(columns, 100, 60)?.indicator).toEqual({ top: 100, left: 0, width: 300 })
        expect(dropTargetAt(columns, 100, 280)?.indicator).toEqual({ top: 200, left: 0, width: 300 })
    })

    it('reports the parent kind, so the caller need not look it up again', () => {
        expect(dropTargetAt(columns, 100, 40)?.kind).toBe('column')
        expect(dropTargetAt(nested, 100, 250)?.kind).toBe('container')
    })
})

describe('dropTargetAt with containers', () => {
    it('prefers the innermost parent under the pointer', () => {
        // A container's box is always inside its column's; preferring the
        // outer one would make containers impossible to aim at.
        expect(dropTargetAt(nested, 100, 240)).toMatchObject({ parent: 'box-1', index: 0 })
        expect(dropTargetAt(nested, 100, 100)).toMatchObject({ parent: 'col-1' })
    })

    it('drops into an EMPTY container', () => {
        expect(dropTargetAt(nested, 100, 350)).toMatchObject({ parent: 'box-2', index: 0 })
    })

    it('orders children of a container by the document', () => {
        expect(dropTargetAt(nested, 100, 260)).toMatchObject({ parent: 'box-1', index: 1 })
    })

    it('refuses a parent the payload may not enter, and takes the next one out', () => {
        const target = dropTargetAt(nested, 100, 350, {
            maxDepth: 6,
            height: 1,
            forbidden: ['box-2'],
        })

        expect(target).toMatchObject({ parent: 'box-1' })
    })

    it('refuses every parent that would nest past the cap', () => {
        const target = dropTargetAt(nested, 100, 350, { maxDepth: 2, height: 1, forbidden: [] })

        // box-2 is depth 3 and box-1 is depth 2; only the column fits a
        // payload that must end no deeper than 2.
        expect(target).toMatchObject({ parent: 'box-1' })
        expect(dropTargetAt(nested, 100, 350, { maxDepth: 1, height: 1, forbidden: [] })).toMatchObject({
            parent: 'col-1',
        })
    })

    it('refuses a tall payload sooner than a short one', () => {
        const short = dropTargetAt(nested, 100, 350, { maxDepth: 3, height: 1, forbidden: [] })
        const tall = dropTargetAt(nested, 100, 350, { maxDepth: 3, height: 2, forbidden: [] })

        expect(short).toMatchObject({ parent: 'box-2' })
        expect(tall).toMatchObject({ parent: 'box-1' })
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
