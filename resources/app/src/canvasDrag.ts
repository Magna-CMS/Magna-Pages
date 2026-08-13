import { computed, ref, type ComputedRef, type Ref } from 'vue'

import type { NodeRect } from './bridge'
import {
    dropTargetAt,
    exceedsThreshold,
    layout,
    sectionDropTargetAt,
    sectionLayout,
    type DropTarget,
    type SectionDropTarget,
} from './dragdrop'
import { placementOf, type DragSource } from './document/placement'

/**
 * One drag, whichever half of the editor it starts in.
 *
 * A block already on the page is dragged with pointer events reported by
 * the canvas bridge; a card in the left panel is dragged with the parent
 * document's own pointer events. Both end up as the same `DragSource` over
 * the same geometry, because the alternative — two drag implementations
 * that must agree about indicators and indexes — is how a builder ends up
 * dropping things somewhere other than where it drew the line.
 *
 * While a panel drag is live the iframe stops taking pointer events, so
 * the parent keeps receiving moves across the canvas. Nothing is injected
 * into the frame for this: the frame's DOM stays exactly what ships.
 */

/** Where a completed drag wants its payload placed. */
export type DropPlacement = { column: string; index: number } | { sectionIndex: number }

export interface CanvasDragOptions {
    rects: Ref<NodeRect[]>
    /** The frame's scroll offset, to convert page coordinates to screen ones. */
    scrollY: Ref<number>
    sectionIds: ComputedRef<string[]>
    blocksByColumn: ComputedRef<Record<string, string[]>>
    /** The element the iframe fills — the origin of frame coordinates. */
    stage: Ref<HTMLElement | null>
    onDrop: (source: DragSource, at: DropPlacement) => void | Promise<void>
}

export function useCanvasDrag(options: CanvasDragOptions) {
    const source = ref<DragSource | null>(null)
    const origin = ref<{ x: number; y: number } | null>(null)
    /** True once the pointer has travelled far enough for this to be a drag. */
    const active = ref(false)
    const columnTarget = ref<DropTarget | null>(null)
    const sectionTarget = ref<SectionDropTarget | null>(null)

    /** The frame ignores the pointer while the parent is tracking a drag. */
    const overCanvas = ref(false)

    const indicator = computed(() =>
        columnTarget.value?.indicator ?? sectionTarget.value?.indicator ?? null,
    )

    function reset() {
        source.value = null
        origin.value = null
        active.value = false
        columnTarget.value = null
        sectionTarget.value = null
        overCanvas.value = false
    }

    /** Recompute the target for a pointer at frame coordinates. */
    function aim(at: { x: number; y: number }) {
        if (!source.value) {
            return
        }

        const placement = placementOf(source.value)
        columnTarget.value = null
        sectionTarget.value = null

        if (placement === 'column') {
            columnTarget.value = dropTargetAt(
                layout(options.rects.value, options.blocksByColumn.value),
                at.x,
                at.y,
            )

            return
        }

        if (placement === 'canvas') {
            const sections = sectionLayout(options.rects.value, options.sectionIds.value)
            const width = options.stage.value?.clientWidth ?? 0
            sectionTarget.value = sectionDropTargetAt(sections, at.y, width)
        }
    }

    /** Client coordinates to the frame's page coordinates, or null if outside. */
    function toFrame(client: { x: number; y: number }): { x: number; y: number } | null {
        const box = options.stage.value?.getBoundingClientRect()
        if (!box) {
            return null
        }

        const inside =
            client.x >= box.left &&
            client.x <= box.right &&
            client.y >= box.top &&
            client.y <= box.bottom

        overCanvas.value = inside

        return inside
            ? { x: client.x - box.left, y: client.y - box.top + options.scrollY.value }
            : null
    }

    async function finish() {
        const dragged = source.value
        const at: DropPlacement | null = columnTarget.value
            ? { column: columnTarget.value.column, index: columnTarget.value.index }
            : sectionTarget.value
              ? { sectionIndex: sectionTarget.value.index }
              : null

        const wasActive = active.value
        reset()

        if (dragged && at && wasActive) {
            await options.onDrop(dragged, at)
        }
    }

    /** A press that may become a drag, wherever the pointer started. */
    function press(dragged: DragSource, at: { x: number; y: number }) {
        source.value = dragged
        origin.value = at
        active.value = false
    }

    /** A press inside the canvas on a node that may be moved. */
    function pressInFrame(nodeId: string, at: { x: number; y: number }) {
        press({ kind: 'move', nodeId }, at)
    }

    /** A pointer move reported by the bridge, already in frame coordinates. */
    function moveInFrame(at: { x: number; y: number }) {
        if (!source.value || !origin.value) {
            return
        }
        if (!active.value && !exceedsThreshold(origin.value, at)) {
            return
        }

        active.value = true
        aim(at)
    }

    /**
     * A press on a panel card. The parent tracks this one itself, because
     * the pointer starts outside the frame and has to cross into it.
     */
    function pressInPanel(dragged: DragSource, event: PointerEvent) {
        press(dragged, { x: event.clientX, y: event.clientY })

        const move = (moved: PointerEvent) => {
            if (!origin.value) {
                return
            }
            const client = { x: moved.clientX, y: moved.clientY }
            if (!active.value && !exceedsThreshold(origin.value, client)) {
                return
            }

            active.value = true
            const at = toFrame(client)
            if (at) {
                aim(at)
            } else {
                columnTarget.value = null
                sectionTarget.value = null
            }
        }

        const up = () => {
            window.removeEventListener('pointermove', move)
            window.removeEventListener('pointerup', up)
            void finish()
        }

        window.addEventListener('pointermove', move)
        window.addEventListener('pointerup', up)
    }

    return {
        source,
        active,
        overCanvas,
        columnTarget,
        sectionTarget,
        indicator,
        press,
        pressInFrame,
        moveInFrame,
        pressInPanel,
        finish,
        cancel: reset,
    }
}
