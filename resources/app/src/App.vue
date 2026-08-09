<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { createApi } from './api'
import { CanvasBridge, debounceByKey, type NodeRect } from './bridge'
import BuilderInspector from './components/BuilderInspector.vue'
import BuilderLayers from './components/BuilderLayers.vue'
import BuilderTopBar from './components/BuilderTopBar.vue'
import { locate } from './document/locate'
import { useDocumentStore } from './stores/document'

/**
 * The builder shell: left rail, canvas, inspector.
 *
 * The canvas is an iframe loading the real rendered page. Selection is drawn
 * as an overlay positioned from rects the bridge reports, rather than by
 * styling nodes inside the frame — the frame's DOM has to stay exactly what
 * ships, so nothing the builder draws may live in it.
 */

const pageId = document.getElementById('magna-builder')?.dataset.page ?? ''
const api = createApi(pageId)
const store = useDocumentStore()

const frame = ref<HTMLIFrameElement | null>(null)
const rects = ref<NodeRect[]>([])
const hovered = ref<string | null>(null)
const canvasHeight = ref(0)
const scrollY = ref(0)

const bridge = new CanvasBridge({
    onRects: (next, height) => {
        rects.value = next
        canvasHeight.value = height
    },
    onSelect: (node) => store.select(node),
    onHover: (node) => (hovered.value = node),
    onScroll: (y) => (scrollY.value = y),
})

/** Re-render one node from current (unsaved) state and swap it in. */
const refreshFragment = debounceByKey(async (node: string) => {
    try {
        const result = await api.fragment(node, store.blocks)
        bridge.applyFragment(node, result.html)
    } catch {
        // A failed fragment leaves the last good markup on screen; the
        // authoritative document is unaffected either way.
    }
}, 120)

const selectedRect = computed(() =>
    rects.value.find((rect) => rect.node === store.selectedNode) ?? null,
)
const hoveredRect = computed(() =>
    hovered.value && hovered.value !== store.selectedNode
        ? (rects.value.find((rect) => rect.node === hovered.value) ?? null)
        : null,
)

const selected = computed(() =>
    store.selectedNode ? locate(store.blocks, store.selectedNode) : null,
)

function overlayStyle(rect: NodeRect) {
    return {
        top: `${rect.top - scrollY.value}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
    }
}

async function onFieldEdit(pointer: string, handle: string, value: unknown) {
    const node = store.selectedNode
    if (!node) {
        return
    }

    const ok = await store.edit(api, `Edit ${handle}`, [
        { op: 'replace', path: `${pointer}/data/${handle}`, value },
    ])

    if (ok) {
        refreshFragment(node)
    }
}

async function onUndo() {
    await store.undo(api)
    reloadCanvas()
}

async function onRedo() {
    await store.redo(api)
    reloadCanvas()
}

function reloadCanvas() {
    // Structural changes move enough nodes that per-node swaps are not worth
    // the bookkeeping — the full reload is under the plan's 1s budget.
    frame.value?.contentWindow?.location.reload()
}

onMounted(async () => {
    if (frame.value) {
        bridge.attach(frame.value)
    }
    await store.load(api)
})

onBeforeUnmount(() => bridge.destroy())
</script>

<template>
    <div class="builder" :class="{ 'is-saving': store.saving }">
        <BuilderTopBar
            :title="store.title"
            :saving="store.saving"
            :can-undo="store.canUndo"
            :can-redo="store.canRedo"
            @undo="onUndo"
            @redo="onRedo"
        />

        <div class="builder__body">
            <aside class="builder__rail">
                <BuilderLayers
                    :sections="store.sections"
                    :selected="store.selectedNode"
                    @select="store.select($event)"
                />
            </aside>

            <main class="builder__canvas">
                <iframe
                    ref="frame"
                    class="builder__frame"
                    :src="api.canvasUrl()"
                    title="Page canvas"
                />

                <div class="builder__overlay" aria-hidden="true">
                    <div
                        v-if="hoveredRect"
                        class="builder__outline builder__outline--hover"
                        :style="overlayStyle(hoveredRect)"
                    />
                    <div
                        v-if="selectedRect"
                        class="builder__outline builder__outline--selected"
                        :style="overlayStyle(selectedRect)"
                    >
                        <span class="builder__label">{{ selectedRect.kind }}</span>
                    </div>
                </div>
            </main>

            <aside class="builder__inspector">
                <BuilderInspector
                    :located="selected"
                    :definition="
                        selected && 'block' in selected.node
                            ? store.blockDefinition(String(selected.node.block))
                            : undefined
                    "
                    :capabilities="store.capabilities"
                    @edit="onFieldEdit"
                />
            </aside>
        </div>

        <p v-if="store.error" class="builder__error" role="alert">{{ store.error }}</p>
    </div>
</template>

<style>
:root {
    --builder-rail: 260px;
    --builder-inspector: 320px;
    --builder-accent: #3d8bfd;
    --builder-surface: #14161d;
    --builder-border: #272b36;
    --builder-text: #e7e9ee;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font: 14px/1.5 system-ui, sans-serif;
    color: var(--builder-text);
    background: var(--builder-surface);
}

.builder {
    display: flex;
    flex-direction: column;
    height: 100vh;
}

.builder__body {
    display: grid;
    grid-template-columns: var(--builder-rail) 1fr var(--builder-inspector);
    flex: 1;
    min-height: 0;
}

.builder__rail,
.builder__inspector {
    overflow-y: auto;
    padding: 12px;
    background: var(--builder-surface);
}

.builder__rail {
    border-right: 1px solid var(--builder-border);
}

.builder__inspector {
    border-left: 1px solid var(--builder-border);
}

.builder__canvas {
    position: relative;
    background: #0b0c10;
}

.builder__frame {
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}

.builder__overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}

.builder__outline {
    position: absolute;
    pointer-events: none;
}

.builder__outline--hover {
    outline: 1px dashed color-mix(in srgb, var(--builder-accent) 60%, transparent);
}

.builder__outline--selected {
    outline: 2px solid var(--builder-accent);
}

.builder__label {
    position: absolute;
    top: -20px;
    left: 0;
    padding: 1px 6px;
    font-size: 11px;
    border-radius: 3px 3px 0 0;
    background: var(--builder-accent);
    color: #fff;
}

.builder__error {
    margin: 0;
    padding: 8px 12px;
    background: #4a1d1d;
    color: #ffd9d9;
}
</style>
