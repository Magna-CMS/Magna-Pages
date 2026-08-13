import { computed, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import type { NodeRect } from './bridge'
import { useCanvasDrag, type DropPlacement } from './canvasDrag'
import type { DragSource } from './document/placement'

/**
 * One section, one column, one block — enough geometry for every routing
 * decision the drag makes. The point of these tests is that the SAME
 * gesture code serves a panel card and a block already on the page, so a
 * regression in one cannot quietly spare the other.
 */
const RECTS: NodeRect[] = [
    { node: 'sec-1', kind: 'section', top: 0, left: 0, width: 800, height: 400 },
    { node: 'col-1', kind: 'column', top: 0, left: 0, width: 800, height: 400 },
    { node: 'blk-1', kind: 'block', top: 0, left: 0, width: 800, height: 100 },
]

function harness(onDrop = vi.fn()) {
    const drag = useCanvasDrag({
        rects: ref(RECTS),
        scrollY: ref(0),
        sectionIds: computed(() => ['sec-1']),
        blocksByColumn: computed(() => ({ 'col-1': ['blk-1'] })),
        // Only clientWidth and getBoundingClientRect are ever read.
        stage: ref({ clientWidth: 800 } as HTMLElement),
        onDrop,
    })

    return { drag, onDrop }
}

describe('moving a block already on the page', () => {
    it('waits for the pointer to travel before it is a drag', () => {
        const { drag } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        drag.moveInFrame({ x: 12, y: 11 })

        expect(drag.active.value).toBe(false)
        expect(drag.indicator.value).toBeNull()
    })

    it('aims at a column once it travels', () => {
        const { drag } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 300 })

        expect(drag.active.value).toBe(true)
        expect(drag.columnTarget.value?.column).toBe('col-1')
        expect(drag.indicator.value).not.toBeNull()
    })

    it('drops with the placement the indicator showed', async () => {
        const { drag, onDrop } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 300 })
        const shown = drag.columnTarget.value
        await drag.finish()

        expect(onDrop).toHaveBeenCalledWith(
            { kind: 'move', nodeId: 'blk-1' } satisfies DragSource,
            { column: 'col-1', index: shown?.index } as DropPlacement,
        )
        expect(drag.indicator.value).toBeNull()
    })

    it('a press that never travelled drops nothing', async () => {
        const { drag, onDrop } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        await drag.finish()

        expect(onDrop).not.toHaveBeenCalled()
    })
})

describe('placement routing', () => {
    /** Drive the same gesture a panel drag runs, minus its DOM listeners. */
    function aimAs(source: DragSource) {
        const { drag, onDrop } = harness()
        drag.press(source, { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 300 })

        return { drag, onDrop }
    }

    it('sends a new block to a column', () => {
        const { drag } = aimAs({ kind: 'new', handle: 'heading' })

        expect(drag.columnTarget.value?.column).toBe('col-1')
        expect(drag.sectionTarget.value).toBeNull()
    })

    it('sends a section-shaped pattern between sections', () => {
        const { drag } = aimAs({ kind: 'pattern', id: 'p1', assetKind: 'section' })

        expect(drag.sectionTarget.value).not.toBeNull()
        expect(drag.columnTarget.value).toBeNull()
    })

    it('sends a block-shaped cloud asset to a column', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'card', assetKind: 'block' })

        expect(drag.columnTarget.value?.column).toBe('col-1')
    })

    it('refuses to aim a whole page anywhere — that is the import dialog', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'site', assetKind: 'page' })

        expect(drag.columnTarget.value).toBeNull()
        expect(drag.sectionTarget.value).toBeNull()
        expect(drag.indicator.value).toBeNull()
    })

    it('refuses an asset kind it has never heard of', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'x', assetKind: 'hologram' })

        expect(drag.indicator.value).toBeNull()
    })
})
