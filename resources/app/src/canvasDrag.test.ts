import { computed, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import type { NodeRect } from './bridge'
import { useCanvasDrag, type DropPlacement } from './canvasDrag'
import type { DragSource } from './document/placement'
import type { DropRules, ParentInfo } from './dragdrop'

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
    // A container occupying the lower half of the column, holding one block.
    { node: 'box-1', kind: 'block', top: 200, left: 0, width: 800, height: 200 },
    { node: 'blk-2', kind: 'block', top: 200, left: 0, width: 800, height: 100 },
]

const PARENTS: ParentInfo[] = [
    { id: 'col-1', kind: 'column', depth: 1, blockIds: ['blk-1', 'box-1'] },
    { id: 'box-1', kind: 'container', depth: 2, blockIds: ['blk-2'] },
]

function harness(onDrop = vi.fn(), rulesFor?: (source: DragSource) => DropRules | undefined) {
    const drag = useCanvasDrag({
        rects: ref(RECTS),
        scrollY: ref(0),
        sectionIds: computed(() => ['sec-1']),
        parents: computed(() => PARENTS),
        // Only clientWidth and getBoundingClientRect are ever read.
        stage: ref({ clientWidth: 800 } as HTMLElement),
        rulesFor,
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
        drag.moveInFrame({ x: 400, y: 150 })

        expect(drag.active.value).toBe(true)
        expect(drag.blockTarget.value?.parent).toBe('col-1')
        expect(drag.indicator.value).not.toBeNull()
    })

    it('drops with the placement the indicator showed', async () => {
        const { drag, onDrop } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 150 })
        const shown = drag.blockTarget.value
        await drag.finish()

        expect(onDrop).toHaveBeenCalledWith(
            { kind: 'move', nodeId: 'blk-1' } satisfies DragSource,
            { parent: 'col-1', index: shown?.index } as DropPlacement,
        )
        expect(drag.indicator.value).toBeNull()
    })

    it('aims INTO a container when the pointer is over one', async () => {
        const { drag, onDrop } = harness()

        drag.pressInFrame('blk-1', { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 380 })

        expect(drag.blockTarget.value?.parent).toBe('box-1')
        expect(drag.blockTarget.value?.kind).toBe('container')

        await drag.finish()
        expect(onDrop).toHaveBeenCalledWith(
            { kind: 'move', nodeId: 'blk-1' } satisfies DragSource,
            { parent: 'box-1', index: 1 } as DropPlacement,
        )
    })

    it('refuses to aim a container into itself, falling back to its column', () => {
        const { drag } = harness(vi.fn(), () => ({
            maxDepth: 6,
            height: 2,
            forbidden: ['box-1', 'blk-2'],
        }))

        drag.pressInFrame('box-1', { x: 10, y: 210 })
        drag.moveInFrame({ x: 400, y: 380 })

        expect(drag.blockTarget.value?.parent).toBe('col-1')
    })

    it('refuses a container that would nest past the depth the server stores', () => {
        const { drag } = harness(vi.fn(), () => ({ maxDepth: 2, height: 2, forbidden: [] }))

        drag.press({ kind: 'new', handle: 'container' }, { x: 10, y: 10 })
        drag.moveInFrame({ x: 400, y: 380 })

        // depth 2 + height 2 - 1 = 3, past the cap: the column takes it.
        expect(drag.blockTarget.value?.parent).toBe('col-1')
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
        drag.moveInFrame({ x: 400, y: 150 })

        return { drag, onDrop }
    }

    it('sends a new block to a column', () => {
        const { drag } = aimAs({ kind: 'new', handle: 'heading' })

        expect(drag.blockTarget.value?.parent).toBe('col-1')
        expect(drag.sectionTarget.value).toBeNull()
    })

    it('sends a section-shaped pattern between sections', () => {
        const { drag } = aimAs({ kind: 'pattern', id: 'p1', assetKind: 'section' })

        expect(drag.sectionTarget.value).not.toBeNull()
        expect(drag.blockTarget.value).toBeNull()
    })

    it('sends a block-shaped cloud asset to a column', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'card', assetKind: 'block' })

        expect(drag.blockTarget.value?.parent).toBe('col-1')
    })

    it('refuses to aim a whole page anywhere — that is the import dialog', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'site', assetKind: 'page' })

        expect(drag.blockTarget.value).toBeNull()
        expect(drag.sectionTarget.value).toBeNull()
        expect(drag.indicator.value).toBeNull()
    })

    it('refuses an asset kind it has never heard of', () => {
        const { drag } = aimAs({ kind: 'library', slug: 'x', assetKind: 'hologram' })

        expect(drag.indicator.value).toBeNull()
    })
})
