<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { createApi } from './api'
import { CanvasBridge, debounceByKey, type NodeRect } from './bridge'
import BuilderAddPanel from './components/BuilderAddPanel.vue'
import BuilderCommandPalette from './components/BuilderCommandPalette.vue'
import BuilderDesignPanel from './components/BuilderDesignPanel.vue'
import BuilderInspector from './components/BuilderInspector.vue'
import BuilderLayers from './components/BuilderLayers.vue'
import BuilderTopBar from './components/BuilderTopBar.vue'
import { columnOf } from './document/edits'
import { locate } from './document/locate'
import { dropTargetAt, exceedsThreshold, layout, type DropTarget } from './dragdrop'
import { buildActions } from './palette'
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

/**
 * Breakpoint preview: the iframe is rendered at true device width, so the
 * page's own media queries decide what shows — the same rules the visitor's
 * browser applies, not an editor simulation of them.
 */
const BREAKPOINTS = { desktop: '100%', tablet: '768px', mobile: '390px' } as const
const breakpoint = ref<keyof typeof BREAKPOINTS>('desktop')

const dragging = ref<string | null>(null)
const dragOrigin = ref<{ x: number; y: number } | null>(null)
const dropTarget = ref<DropTarget | null>(null)

/** Block ids per column, so drop math orders by the document, not geometry. */
const blocksByColumn = computed<Record<string, string[]>>(() => {
    const map: Record<string, string[]> = {}

    for (const section of store.sections) {
        for (const column of section.columns ?? []) {
            map[column.id] = (column.blocks ?? []).map((block) => block.id)
        }
    }

    return map
})

const bridge = new CanvasBridge({
    onRects: (next, height) => {
        rects.value = next
        canvasHeight.value = height
    },
    onSelect: (node) => store.select(node),
    onHover: (node) => (hovered.value = node),
    onScroll: (y) => (scrollY.value = y),

    onPointerDown: (node, at) => {
        // Remember where a press started; it only becomes a drag once it
        // travels, so a click stays a click.
        if (store.capabilities.structure && locate(store.blocks, node)?.kind === 'block') {
            dragging.value = node
            dragOrigin.value = at
        }
    },

    onPointerMove: (at) => {
        if (!dragging.value || !dragOrigin.value) {
            return
        }
        if (!exceedsThreshold(dragOrigin.value, at)) {
            return
        }

        dropTarget.value = dropTargetAt(
            layout(rects.value, blocksByColumn.value),
            at.x,
            at.y,
        )
    },

    onPointerUp: () => {
        void finishDrag()
    },

    onEditRequest: (node) => {
        if (editableField(node) !== null) {
            store.select(node)
            bridge.setEditable(node, true)
        }
    },

    onTextCommit: (node, text) => {
        void commitText(node, text)
    },

    onUneditable: () => {
        // The element carries markup; the inspector is the editing path.
    },
})

/**
 * The field inline editing writes to: the block's first plain-text field.
 * Fields with markup (richtext) are excluded — the bridge edits innerText,
 * and writing that over stored markup would destroy it.
 */
function editableField(nodeId: string): string | null {
    if (!store.capabilities.content) {
        return null
    }

    const found = locate(store.blocks, nodeId)
    if (!found || found.kind !== 'block') {
        return null
    }

    const definition = store.blockDefinition(String((found.node as { block: string }).block))
    const field = definition?.fields.find(
        (entry) => entry.type === 'text' || entry.type === 'textarea',
    )

    return field?.handle ?? null
}

async function commitText(nodeId: string, text: string) {
    const handle = editableField(nodeId)
    const found = locate(store.blocks, nodeId)
    if (!handle || !found) {
        return
    }

    const ok = await store.edit(api, 'Edit text', [
        { op: 'replace', path: `${found.pointer}/data/${handle}`, value: text },
    ])

    // Re-render the node either way: on success the canvas shows the stored
    // (sanitized) text; on refusal it snaps back to the document's truth.
    refreshFragment(nodeId)

    return ok
}

async function finishDrag() {
    const node = dragging.value
    const target = dropTarget.value

    dragging.value = null
    dragOrigin.value = null
    dropTarget.value = null

    if (!node || !target) {
        return
    }

    if (await store.moveBlock(api, node, target.column, target.index)) {
        reloadCanvas()
    }
}

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

async function onSettingEdit(pointer: string, key: string, value: unknown) {
    // `add` rather than `replace`: settings keys (visibility, anchor) may
    // not exist on the node yet, and add-on-an-object is upsert.
    const ok = await store.edit(api, `Edit ${key}`, [
        { op: 'add', path: `${pointer}/settings/${key}`, value },
    ])

    if (ok) {
        // Section settings change wrappers the fragment loop does not cover.
        reloadCanvas()
    }
}

/**
 * Where a new block would go: the selected column, or the column holding
 * the selected block, so "select a heading, add a paragraph" lands where the
 * user is looking rather than at the end of the page.
 */
const targetColumn = computed<string | null>(() => {
    if (!selected.value) {
        return null
    }

    return selected.value.kind === 'column'
        ? String((selected.value.node as { id: string }).id)
        : columnOf(store.blocks, String((selected.value.node as { id: string }).id))
})

async function onAddBlock(handle: string) {
    if (targetColumn.value && (await store.addBlock(api, targetColumn.value, handle))) {
        reloadCanvas()
    }
}

async function onAddSection() {
    if (await store.addSection(api)) {
        reloadCanvas()
    }
}

async function onDelete() {
    if (store.selectedNode && (await store.removeNode(api, store.selectedNode))) {
        reloadCanvas()
    }
}

/** Command palette. */
const paletteOpen = ref(false)

const paletteActions = computed(() =>
    buildActions({
        capabilities: store.capabilities,
        lockMine: store.lock.mine,
        hasSelection: store.selectedNode !== null,
        targetColumn: targetColumn.value,
        blocks: store.registry.map((definition) => ({
            handle: definition.handle,
            label: definition.label,
            requiresPermission: definition.requiresPermission,
        })),
        patterns: store.patterns,
        breakpoints: Object.keys(BREAKPOINTS),
        handlers: {
            addBlock: (handle) => void onAddBlock(handle),
            addSection: () => void onAddSection(),
            insertPattern: (id) => void onInsertPattern(id),
            setBreakpoint: (device) => (breakpoint.value = device as keyof typeof BREAKPOINTS),
            undo: () => void onUndo(),
            redo: () => void onRedo(),
            deleteSelection: () => void onDelete(),
            publish: () => void onPublish(),
            savePattern: () => void onSavePattern(),
        },
    }),
)

function onKeydown(event: KeyboardEvent) {
    // The palette opens from anywhere, even mid-typing — that is the point
    // of a global command surface.
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        paletteOpen.value = !paletteOpen.value

        return
    }

    const target = event.target as HTMLElement | null
    const typing =
        target?.tagName === 'INPUT' || target?.tagName === 'TEXTAREA' || target?.isContentEditable

    if (typing) {
        return
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault()
        void (event.shiftKey ? onRedo() : onUndo())

        return
    }

    if ((event.key === 'Delete' || event.key === 'Backspace') && store.selectedNode) {
        event.preventDefault()
        void onDelete()
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

/**
 * Heartbeat at a third of the server TTL (90s): one lost request does not
 * cost the lock, two might, three means the tab really is gone.
 */
let heartbeatTimer: ReturnType<typeof setInterval> | null = null

function startHeartbeat() {
    heartbeatTimer = setInterval(async () => {
        if (!store.lock.mine) {
            return
        }
        try {
            await api.heartbeat()
        } catch {
            store.lockLost(null)
        }
    }, 30_000)
}

async function onPublish() {
    await store.publish(api)
}

/** Design tab state: theme tokens + site overrides. */
const themeTokens = ref<Record<string, string>>({})
const styleOverrides = ref<Record<string, string>>({})
const savingStyles = ref(false)

async function loadStyles() {
    try {
        const styles = await api.styles()
        themeTokens.value = styles.theme
        styleOverrides.value = styles.overrides
    } catch {
        // No styles endpoint response leaves the Design tab empty; the
        // builder itself is unaffected.
    }
}

function onStylePreview(tokens: Record<string, string>) {
    bridge.applyTokens(tokens)
}

async function onStyleSave(tokens: Record<string, string>) {
    savingStyles.value = true
    try {
        const result = await api.saveStyles(tokens)
        styleOverrides.value = result.overrides
        bridge.applyTokens(result.effective)
    } catch (error) {
        store.error = error instanceof Error ? error.message : String(error)
    } finally {
        savingStyles.value = false
    }
}

async function onInsertPattern(id: string) {
    if (await store.insertPattern(api, id, targetColumn.value)) {
        reloadCanvas()
    }
}

async function onSavePattern() {
    // A window.prompt is deliberate v1: naming is the only input, and a
    // modal component for one string is UI the feature does not need yet.
    const name = window.prompt('Pattern name')
    if (name === null) {
        return
    }

    await store.saveAsPattern(api, name)
}

async function onTakeOver() {
    await store.takeOver(api)
    reloadCanvas()
}

function onUnload() {
    if (store.lock.mine) {
        api.release()
    }
}

onMounted(async () => {
    if (frame.value) {
        bridge.attach(frame.value)
    }
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('pagehide', onUnload)
    await store.load(api)
    void store.loadPatterns(api)
    void loadStyles()
    startHeartbeat()
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('pagehide', onUnload)
    if (heartbeatTimer !== null) {
        clearInterval(heartbeatTimer)
    }
    bridge.destroy()
})
</script>

<template>
    <div class="builder" :class="{ 'is-saving': store.saving }">
        <BuilderTopBar
            :title="store.title"
            :status="store.status"
            :public-url="store.publicUrl"
            :saving="store.saving"
            :can-undo="store.canUndo"
            :can-redo="store.canRedo"
            :can-delete="store.selectedNode !== null && store.capabilities.structure"
            :can-publish="store.capabilities.publish && store.lock.mine"
            :can-save-pattern="
                store.selectedNode !== null &&
                store.capabilities.structure &&
                selected?.kind !== 'column'
            "
            @undo="onUndo"
            @redo="onRedo"
            @remove="onDelete"
            @publish="onPublish"
            @save-pattern="onSavePattern"
        />

        <div v-if="!store.lock.mine && store.loaded" class="builder__lockbar" role="alert">
            <span>
                {{
                    store.lock.holder
                        ? `${store.lock.holder.name} is editing this page — your changes will not save.`
                        : 'You no longer hold the edit lock — your changes will not save.'
                }}
            </span>
            <button type="button" @click="onTakeOver">Take over</button>
        </div>

        <div class="builder__body">
            <aside class="builder__rail">
                <BuilderLayers
                    :sections="store.sections"
                    :selected="store.selectedNode"
                    @select="store.select($event)"
                />

                <BuilderAddPanel
                    :registry="store.registry"
                    :sections="store.sections"
                    :target-column="targetColumn"
                    :capabilities="store.capabilities"
                    :patterns="store.patterns"
                    @add="onAddBlock"
                    @add-section="onAddSection"
                    @insert-pattern="onInsertPattern"
                />

                <BuilderDesignPanel
                    :theme="themeTokens"
                    :overrides="styleOverrides"
                    :capabilities="store.capabilities"
                    :saving="savingStyles"
                    @preview="onStylePreview"
                    @save="onStyleSave"
                />
            </aside>

            <main class="builder__canvas">
                <div class="builder__viewport-bar">
                    <button
                        v-for="(_width, device) in BREAKPOINTS"
                        :key="device"
                        type="button"
                        class="builder__viewport"
                        :class="{ 'is-active': breakpoint === device }"
                        @click="breakpoint = device"
                    >
                        {{ device }}
                    </button>
                </div>

                <div class="builder__stage" :style="{ width: BREAKPOINTS[breakpoint] }">
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

                    <div
                        v-if="dropTarget"
                        class="builder__drop"
                        :style="{
                            top: `${dropTarget.indicator.top - scrollY}px`,
                            left: `${dropTarget.indicator.left}px`,
                            width: `${dropTarget.indicator.width}px`,
                        }"
                    />
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
                    @edit-setting="onSettingEdit"
                />
            </aside>
        </div>

        <p v-if="store.error" class="builder__error" role="alert">{{ store.error }}</p>

        <BuilderCommandPalette
            :open="paletteOpen"
            :actions="paletteActions"
            @close="paletteOpen = false"
        />
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
    display: flex;
    flex-direction: column;
    align-items: center;
    background: #0b0c10;
}

.builder__viewport-bar {
    display: flex;
    gap: 4px;
    padding: 6px;
}

.builder__viewport {
    padding: 2px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 999px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-transform: capitalize;
    cursor: pointer;
}

.builder__viewport.is-active {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
}

/* The stage carries the device width; iframe and overlay both fill it, so
   rects reported in frame coordinates stay aligned at every breakpoint. */
.builder__stage {
    position: relative;
    flex: 1;
    max-width: 100%;
    min-height: 0;
    transition: width 0.15s ease;
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

.builder__drop {
    position: absolute;
    height: 3px;
    border-radius: 2px;
    background: var(--builder-accent);
    box-shadow: 0 0 0 1px rgb(0 0 0 / 35%);
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

.builder__lockbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 12px;
    background: #4a3a12;
    color: #ffe9b3;
}

.builder__lockbar button {
    padding: 3px 10px;
    border: 1px solid currentcolor;
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.builder__error {
    margin: 0;
    padding: 8px 12px;
    background: #4a1d1d;
    color: #ffd9d9;
}
</style>
