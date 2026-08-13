<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { createApi } from './api'
import { CanvasBridge, debounceByKey, type NodeRect } from './bridge'
import BuilderCommandPalette from './components/BuilderCommandPalette.vue'
import BuilderDesignPanel from './components/BuilderDesignPanel.vue'
import BuilderDock from './components/BuilderDock.vue'
import BuilderInspector from './components/BuilderInspector.vue'
import BuilderLayers from './components/BuilderLayers.vue'
import BuilderLibrary from './components/BuilderLibrary.vue'
import BuilderPanel from './components/BuilderPanel.vue'
import BuilderToolsPanel from './components/BuilderToolsPanel.vue'
import BuilderTopBar from './components/BuilderTopBar.vue'
import { useCanvasDrag, type DropPlacement } from './canvasDrag'
import { columnOf, exportAsLibraryAsset, primaryTextField, sectionOf } from './document/edits'
import { locate } from './document/locate'
import { needsImportFlow, type DragSource } from './document/placement'
import { classifyFailure } from './resilience'
import { buildActions } from './palette'
import type { SectionNode } from './document/types'
import { useDocumentStore } from './stores/document'
import { useUiStore, type Breakpoint } from './stores/ui'

/**
 * The builder shell: one left panel, the canvas, a bottom dock.
 *
 * The panel switches between adding and editing rather than standing beside
 * a second rail, and the occasional surfaces (navigator, checks, comments,
 * design) live in dock drawers — width belongs to the page being built.
 *
 * The canvas is an iframe loading the real rendered page. Selection is drawn
 * as an overlay positioned from rects the bridge reports, rather than by
 * styling nodes inside the frame — the frame's DOM has to stay exactly what
 * ships, so nothing the builder draws may live in it.
 */

const pageId = document.getElementById('magna-builder')?.dataset.page ?? ''
const api = createApi(pageId)
const store = useDocumentStore()
const ui = useUiStore()

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
const BREAKPOINTS: Record<Breakpoint, string> = { desktop: '100%', tablet: '768px', mobile: '390px' }

const stage = ref<HTMLElement | null>(null)

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

const sectionIds = computed(() => store.sections.map((section) => section.id))

const drag = useCanvasDrag({
    rects,
    scrollY,
    sectionIds,
    blocksByColumn,
    stage,
    onDrop: (source, at) => onDrop(source, at),
})

const bridge = new CanvasBridge({
    onRects: (next, height) => {
        rects.value = next
        canvasHeight.value = height
    },
    onSelect: (node) => selectNode(node),
    onHover: (node) => (hovered.value = node),
    onScroll: (y) => (scrollY.value = y),

    onPointerDown: (node, at) => {
        // Remember where a press started; it only becomes a drag once it
        // travels, so a click stays a click.
        if (store.capabilities.structure && locate(store.blocks, node)?.kind === 'block') {
            drag.pressInFrame(node, at)
        }
    },

    onPointerMove: (at) => drag.moveInFrame(at),

    onPointerUp: () => {
        void drag.finish()
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
    if (!store.capabilities.content || !store.lock.mine) {
        return null
    }

    const found = locate(store.blocks, nodeId)
    if (!found || found.kind !== 'block') {
        return null
    }

    const definition = store.blockDefinition(String((found.node as { block: string }).block))
    const field = definition ? primaryTextField(definition) : null
    if (!field) {
        return null
    }

    // A bound field resolves at render; typing over the resolved output
    // would silently replace the binding with a literal.
    const value = (found.node as { data?: Record<string, unknown> }).data?.[field]
    if (typeof value === 'object' && value !== null && '$bind' in value) {
        return null
    }

    return field
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

/**
 * A completed drop, whatever was dragged. The placement table already
 * refused anything that cannot land here, so this only has to route the
 * payload to the producer that knows how to insert it.
 */
async function onDrop(source: DragSource, at: DropPlacement) {
    const inColumn = 'column' in at

    if (source.kind === 'move') {
        if (inColumn && (await store.moveBlock(api, source.nodeId, at.column, at.index))) {
            reloadCanvas()
        }

        return
    }

    if (source.kind === 'new') {
        if (inColumn && (await store.addBlock(api, at.column, source.handle, at.index))) {
            ui.inspect('content')
            reloadCanvas()
        }

        return
    }

    if (source.kind === 'pattern') {
        if (await store.insertPattern(api, source.id, at)) {
            reloadCanvas()
        }

        return
    }

    if (confirmMissingBlocks(source.slug) && (await store.insertLibraryAsset(api, source.slug, at))) {
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

/**
 * The row whose layout the inspector may edit: the selected section, or
 * the parent of the selected column.
 */
const layoutSection = computed<SectionNode | null>(() => {
    if (!selected.value) {
        return null
    }
    if (selected.value.kind === 'section') {
        return selected.value.node as SectionNode
    }

    return selected.value.kind === 'column'
        ? sectionOf(store.blocks, String((selected.value.node as { id: string }).id))
        : null
})

/** What the panel's Edit tab is currently about. */
const selectionLabel = computed<string | null>(() => {
    if (!selected.value) {
        return null
    }

    return selected.value.kind === 'block'
        ? String((selected.value.node as { block?: string }).block ?? 'block')
        : selected.value.kind
})

/**
 * Selecting on the canvas or in the navigator moves the panel to that
 * node's settings, the way Elementor does — a click that visibly does
 * nothing reads as a broken editor.
 *
 * Deliberately NOT a watcher on the selection: adding a section also
 * selects it, and that flow must stay in the library so the next step
 * (drop an element into the new row) is one click away.
 */
function selectNode(node: string | null) {
    store.select(node)
    if (node !== null) {
        ui.inspect(ui.inspectTab)
    }
}

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

    const id = String((selected.value.node as { id: string }).id)

    if (selected.value.kind === 'column') {
        return id
    }

    // A selected SECTION targets its first column, so "add a section, then
    // add an element" works without a second click into the column — the
    // approved flow is Add Section → Choose Columns → Drag Element, and
    // making the middle step mandatory would break it.
    if (selected.value.kind === 'section') {
        const columns = (selected.value.node as { columns?: { id: string }[] }).columns ?? []

        return columns.length > 0 ? String(columns[0].id) : null
    }

    return columnOf(store.blocks, id)
})

async function onAddBlock(handle: string) {
    if (targetColumn.value && (await store.addBlock(api, targetColumn.value, handle))) {
        // A placed element is one the editor wants to fill in next.
        ui.inspect('content')
        reloadCanvas()
    }
}

async function onAddSection(spans: number[] = [12]) {
    if (await store.addSection(api, spans)) {
        // Stay in the library: the next step of the workflow is dropping an
        // element into the row that just appeared.
        reloadCanvas()
    }
}

async function onDelete() {
    if (store.selectedNode && (await store.removeNode(api, store.selectedNode))) {
        reloadCanvas()
    }
}

async function onAddColumn(sectionId: string) {
    if (await store.addColumn(api, sectionId)) {
        reloadCanvas()
    }
}

async function onRemoveColumn(sectionId: string, columnId: string) {
    if (await store.removeColumn(api, sectionId, columnId)) {
        reloadCanvas()
    }
}

async function onSetSpans(sectionId: string, spans: number[]) {
    if (await store.setSpans(api, sectionId, spans)) {
        reloadCanvas()
    }
}

async function onDuplicate() {
    if (store.selectedNode && (await store.duplicateNode(api, store.selectedNode))) {
        reloadCanvas()
    }
}

/**
 * Move the selected section one place up or down. Only sections reorder
 * from the toolbar — a block moves by dragging, where the target column is
 * part of the gesture rather than guesswork.
 */
async function onMoveSection(delta: number) {
    const id = store.selectedNode
    const index = store.sections.findIndex((section) => section.id === id)
    if (id === null || index < 0) {
        return
    }

    const target = index + delta
    if (target < 0 || target >= store.sections.length) {
        return
    }

    if (await store.moveSection(api, id, target)) {
        reloadCanvas()
    }
}

/** Toolbar affordances for whatever is selected right now. */
const TOOLBAR_HEIGHT = 26

const toolbar = computed(() => {
    const kind = selected.value?.kind ?? null
    const structural = store.capabilities.structure && store.lock.mine
    const rect = selectedRect.value
    const top = rect ? rect.top - scrollY.value : 0

    return {
        show: kind !== null && rect !== null,
        // Above the element normally; tucked inside its top edge when there
        // is no room, rather than floating over whatever sits above it.
        top: top >= TOOLBAR_HEIGHT ? top - TOOLBAR_HEIGHT : top + 2,
        left: rect?.left ?? 0,
        canDuplicate: structural && (kind === 'section' || kind === 'block'),
        canDelete: structural && kind !== null,
        canMove: structural && kind === 'section',
    }
})

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
            setBreakpoint: (device) => (ui.breakpoint = device as Breakpoint),
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
        } catch (error) {
            // Only a server ANSWER takes the lock away. A network failure
            // means offline — the server-side TTL is the arbiter there, and
            // the replay path surfaces the outcome when we reconnect.
            if (classifyFailure(error) === 'refusal') {
                store.lockLost(null)
            }
        }
    }, 30_000)
}

async function onPublish() {
    await store.publish(api)
}

async function onRequestPublish() {
    const note = window.prompt('Anything the reviewer should know? (optional)')
    if (note === null) {
        return
    }

    await store.requestPublish(api, note.trim() === '' ? null : note.trim())
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

/**
 * Missing blocks render as nothing on the public site — insertable, but
 * never silently: the person choosing gets to decide with the facts.
 */
function confirmMissingBlocks(slug: string): boolean {
    const asset = store.libraryAssets.find((entry) => entry.slug === slug)
    if (!asset || asset.missingBlocks.length === 0) {
        return true
    }

    return window.confirm(
        `This site is missing: ${asset.missingBlocks.join(', ')}. ` +
            'Those blocks will not display until their plugin is installed. Insert anyway?',
    )
}

/** The page asset waiting on a replace-or-append decision. */
const pendingImport = ref<{ slug: string; name: string } | null>(null)

async function onInsertLibrary(slug: string) {
    const asset = store.libraryAssets.find((entry) => entry.slug === slug)

    // A whole page is never inserted by a click: it would silently discard
    // the document being edited. It gets an explicit decision instead.
    if (asset && needsImportFlow({ kind: 'library', slug, assetKind: asset.kind })) {
        pendingImport.value = { slug, name: asset.name }

        return
    }

    if (!confirmMissingBlocks(slug)) {
        return
    }

    // A click has no target of its own, so a block-shaped asset joins the
    // end of the column the selection is in. A drag supplies an exact one.
    const at = targetColumn.value ? { column: targetColumn.value } : undefined

    if (await store.insertLibraryAsset(api, slug, at)) {
        reloadCanvas()
    }
}

async function onImportPage(mode: 'replace' | 'append') {
    const asset = pendingImport.value
    pendingImport.value = null

    if (asset && confirmMissingBlocks(asset.slug) && (await store.importPageAsset(api, asset.slug, mode))) {
        reloadCanvas()
    }
}

async function onInsertPattern(id: string) {
    // Click-to-insert: a block pattern joins the column the selection is
    // in, a section pattern appends. A drag supplies an exact target.
    const at = targetColumn.value ? { column: targetColumn.value } : undefined

    if (await store.insertPattern(api, id, at)) {
        reloadCanvas()
    }
}

/**
 * Export the selection (or the whole page) as a library asset file — the
 * JSON the hub authoring screen accepts. Download, not clipboard: a file
 * survives the trip to another machine and can sit in a repo.
 */
function onExportLibrary() {
    const name = window.prompt('Asset name for the library export')
    if (name === null || name.trim() === '') {
        return
    }

    const asset = exportAsLibraryAsset(store.blocks, store.selectedNode, name.trim())
    if (!asset) {
        store.error = 'Select a section or block to export, or deselect to export the page.'

        return
    }

    const blob = new Blob([JSON.stringify(asset, null, 2)], { type: 'application/json' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `${asset.kind}-${name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-')}.json`
    link.click()
    URL.revokeObjectURL(link.href)
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

/** Checks & History panel state (pull surfaces — loaded on demand). */
const a11yFindings = ref<
    { code: string; severity: string; nodeId: string | null; message: string }[] | null
>(null)
const a11yRunning = ref(false)
const perfReport = ref<{ metrics: Record<string, number>; notes: { code: string; message: string }[] } | null>(null)
const perfRunning = ref(false)
const revisions = ref<
    { id: string; kind: string; label: string | null; author: string | null; createdAt: string }[] | null
>(null)
const revisionsLoading = ref(false)
const previewedRevision = ref<string | null>(null)
const comments = ref<
    { id: string; nodeId: string | null; body: string; author: string | null; resolved: boolean; createdAt: string | null }[] | null
>(null)
const commentsLoading = ref(false)

async function onLoadComments() {
    commentsLoading.value = true
    try {
        comments.value = (await api.comments()).comments
    } catch {
        store.error = 'Could not load comments.'
    } finally {
        commentsLoading.value = false
    }
}

async function onAddComment(body: string, nodeId: string | null) {
    try {
        await api.addComment(body, nodeId)
        await onLoadComments()
    } catch (failure) {
        store.error = failure instanceof Error ? failure.message : 'Could not add the comment.'
    }
}

async function onResolveComment(commentId: string) {
    try {
        await api.resolveComment(commentId)
        await onLoadComments()
    } catch {
        store.error = 'Could not resolve the comment.'
    }
}

async function onRunA11y() {
    a11yRunning.value = true
    try {
        a11yFindings.value = (await api.a11y()).findings
    } catch {
        store.error = 'The accessibility check failed to run.'
    } finally {
        a11yRunning.value = false
    }
}

async function onRunPerformance() {
    perfRunning.value = true
    try {
        perfReport.value = await api.performance()
    } catch {
        store.error = 'The performance measurement failed to run.'
    } finally {
        perfRunning.value = false
    }
}

async function onLoadRevisions() {
    revisionsLoading.value = true
    try {
        revisions.value = (await api.revisions()).revisions
    } catch {
        store.error = 'Could not load the revision history.'
    } finally {
        revisionsLoading.value = false
    }
}

async function onRestoreRevision(revisionId: string) {
    if (!window.confirm('Restore this revision? The current state is snapshotted first, so this is reversible.')) {
        return
    }

    try {
        await api.restoreRevision(revisionId)
    } catch (failure) {
        store.error = failure instanceof Error ? failure.message : 'Restore failed.'

        return
    }

    // The document changed out from under every piece of client state —
    // undo stack, canvas, inspector. A clean reload is the honest reset.
    window.location.reload()
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

/** Replay the send queue when the network returns, and on a slow tick. */
let replayTimer: ReturnType<typeof setInterval> | null = null

function onOnline() {
    void store.replayQueue(api)
}

onMounted(async () => {
    if (frame.value) {
        bridge.attach(frame.value)
    }
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('pagehide', onUnload)
    window.addEventListener('online', onOnline)
    replayTimer = setInterval(() => void store.replayQueue(api), 15_000)

    await store.load(api)
    await store.restoreQueue(api)
    void store.loadPatterns(api)
    void store.loadLibrary(api)
    void loadStyles()
    startHeartbeat()
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('pagehide', onUnload)
    window.removeEventListener('online', onOnline)
    if (heartbeatTimer !== null) {
        clearInterval(heartbeatTimer)
    }
    if (replayTimer !== null) {
        clearInterval(replayTimer)
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
            :can-request-publish="store.capabilities.content"
            :publish-requested="store.approval !== null"
            :pending-count="store.sendQueue.length"
            @undo="onUndo"
            @redo="onRedo"
            @remove="onDelete"
            @publish="onPublish"
            @save-pattern="onSavePattern"
            @request-publish="onRequestPublish"
            @export-library="onExportLibrary"
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
            <BuilderPanel :selection-label="selectionLabel">
                <template #library>
                    <BuilderLibrary
                        :registry="store.registry"
                        :target-column="targetColumn"
                        :capabilities="store.capabilities"
                        :patterns="store.patterns"
                        :library-assets="store.libraryAssets"
                        :library-collections="store.libraryCollections"
                        @add="onAddBlock"
                        @add-section="onAddSection"
                        @insert-pattern="onInsertPattern"
                        @insert-library="onInsertLibrary"
                        @drag-start="drag.pressInPanel"
                    />
                </template>

                <template #inspect>
                    <BuilderInspector
                        :located="selected"
                        :definition="
                            selected && 'block' in selected.node
                                ? store.blockDefinition(String(selected.node.block))
                                : undefined
                        "
                        :capabilities="store.capabilities"
                        :binding-sources="store.bindingSources"
                        :layout-section="layoutSection"
                        @edit="onFieldEdit"
                        @edit-setting="onSettingEdit"
                        @add-column="onAddColumn"
                        @remove-column="onRemoveColumn"
                        @set-spans="onSetSpans"
                        @select="selectNode($event)"
                    />
                </template>
            </BuilderPanel>

            <main class="builder__canvas">
                <div class="builder__viewport-bar">
                    <button
                        v-for="(_width, device) in BREAKPOINTS"
                        :key="device"
                        type="button"
                        class="builder__viewport"
                        :class="{ 'is-active': ui.breakpoint === device }"
                        @click="ui.breakpoint = device"
                    >
                        {{ device }}
                    </button>
                </div>

                <div ref="stage" class="builder__stage" :style="{ width: BREAKPOINTS[ui.breakpoint] }">
                    <!-- The frame stops taking pointer events while a panel
                         drag is in flight, so the parent keeps receiving the
                         moves that cross over the canvas. -->
                    <iframe
                        ref="frame"
                        class="builder__frame"
                        :class="{ 'is-inert': drag.active.value }"
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
                        v-if="drag.indicator.value"
                        class="builder__drop"
                        :style="{
                            top: `${drag.indicator.value.top - scrollY}px`,
                            left: `${drag.indicator.value.left}px`,
                            width: `${drag.indicator.value.width}px`,
                        }"
                    />
                    </div>

                    <!-- The element toolbar: the actions that belong to the
                         thing under the cursor, next to it rather than in a
                         panel the eye has to travel to. Drawn outside the
                         frame like every other overlay. -->
                    <div
                        v-if="toolbar.show"
                        class="builder__toolbar"
                        :style="{ top: `${toolbar.top}px`, left: `${toolbar.left}px` }"
                    >
                        <span class="builder__toolbar-kind">{{ selectionLabel }}</span>

                        <button
                            v-if="toolbar.canMove"
                            type="button"
                            title="Move up"
                            aria-label="Move section up"
                            @click="onMoveSection(-1)"
                        >
                            ↑
                        </button>
                        <button
                            v-if="toolbar.canMove"
                            type="button"
                            title="Move down"
                            aria-label="Move section down"
                            @click="onMoveSection(1)"
                        >
                            ↓
                        </button>
                        <button
                            v-if="toolbar.canDuplicate"
                            type="button"
                            title="Duplicate"
                            aria-label="Duplicate selection"
                            @click="onDuplicate"
                        >
                            ⧉
                        </button>
                        <button
                            type="button"
                            :disabled="!toolbar.canDelete"
                            title="Delete"
                            aria-label="Delete selection"
                            @click="onDelete"
                        >
                            🗑
                        </button>
                    </div>

                    <!-- An empty page with no instructions is where a first
                         session stalls; the way forward is the first step of
                         the workflow, not a decoration. -->
                    <div v-if="store.loaded && store.sections.length === 0" class="builder__empty">
                        <p>This page is empty.</p>
                        <button
                            type="button"
                            :disabled="!store.capabilities.structure"
                            @click="onAddSection([12])"
                        >
                            Add your first section
                        </button>
                        <small>Or pick a column structure in the Add panel.</small>
                    </div>
                </div>
            </main>
        </div>

        <BuilderDock :comment-count="comments?.filter((entry) => !entry.resolved).length ?? null">
            <template #layers>
                <BuilderLayers
                    :sections="store.sections"
                    :selected="store.selectedNode"
                    @select="selectNode($event)"
                />
            </template>

            <template #design>
                <BuilderDesignPanel
                    :theme="themeTokens"
                    :overrides="styleOverrides"
                    :capabilities="store.capabilities"
                    :saving="savingStyles"
                    @preview="onStylePreview"
                    @save="onStyleSave"
                />
            </template>

            <template #checks>
                <BuilderToolsPanel
                    :groups="['checks', 'history']"
                    :a11y-findings="a11yFindings"
                    :a11y-running="a11yRunning"
                    :performance="perfReport"
                    :performance-running="perfRunning"
                    :revisions="revisions"
                    :revisions-loading="revisionsLoading"
                    :can-restore="store.lock.mine && store.capabilities.content"
                    :comments="comments"
                    :comments-loading="commentsLoading"
                    :selected-node="store.selectedNode"
                    @run-a11y="onRunA11y"
                    @run-performance="onRunPerformance"
                    @load-revisions="onLoadRevisions"
                    @preview-revision="previewedRevision = $event"
                    @restore-revision="onRestoreRevision"
                    @select-node="selectNode($event)"
                    @load-comments="onLoadComments"
                    @add-comment="onAddComment"
                    @resolve-comment="onResolveComment"
                />
            </template>

            <template #comments>
                <BuilderToolsPanel
                    :groups="['comments']"
                    :a11y-findings="a11yFindings"
                    :a11y-running="a11yRunning"
                    :performance="perfReport"
                    :performance-running="perfRunning"
                    :revisions="revisions"
                    :revisions-loading="revisionsLoading"
                    :can-restore="store.lock.mine && store.capabilities.content"
                    :comments="comments"
                    :comments-loading="commentsLoading"
                    :selected-node="store.selectedNode"
                    @run-a11y="onRunA11y"
                    @run-performance="onRunPerformance"
                    @load-revisions="onLoadRevisions"
                    @preview-revision="previewedRevision = $event"
                    @restore-revision="onRestoreRevision"
                    @select-node="selectNode($event)"
                    @load-comments="onLoadComments"
                    @add-comment="onAddComment"
                    @resolve-comment="onResolveComment"
                />
            </template>
        </BuilderDock>

        <p v-if="store.error" class="builder__error" role="alert">{{ store.error }}</p>

        <!-- Revision diff: the revision and the current page side by side,
             both rendered by the one real renderer. -->
        <div
            v-if="previewedRevision"
            class="builder__diff"
            role="dialog"
            aria-label="Revision comparison"
        >
            <div class="builder__diff-bar">
                <span>Comparing revision against the current page</span>
                <div class="builder__diff-actions">
                    <button
                        type="button"
                        :disabled="!(store.lock.mine && store.capabilities.content)"
                        @click="onRestoreRevision(previewedRevision)"
                    >
                        Restore this revision
                    </button>
                    <button type="button" @click="previewedRevision = null">Close</button>
                </div>
            </div>
            <div class="builder__diff-panes">
                <figure class="builder__diff-pane">
                    <figcaption>Revision</figcaption>
                    <iframe :src="api.revisionPreviewUrl(previewedRevision)" title="Revision preview" />
                </figure>
                <figure class="builder__diff-pane">
                    <figcaption>Current</figcaption>
                    <iframe :src="api.canvasUrl()" title="Current page" />
                </figure>
            </div>
        </div>

        <!-- Importing a page is a decision, not a drop: it can replace
             everything on the canvas, so it says so and asks. -->
        <div
            v-if="pendingImport"
            class="builder__modal"
            role="dialog"
            aria-label="Import page"
        >
            <div class="builder__modal-box">
                <h2>Import “{{ pendingImport.name }}”</h2>
                <p>
                    This asset is a whole page. Replace what is on the canvas, or add its
                    sections to the end of this one?
                </p>
                <div class="builder__modal-actions">
                    <button type="button" @click="onImportPage('append')">Add to this page</button>
                    <button type="button" @click="onImportPage('replace')">
                        Replace this page
                    </button>
                    <button type="button" @click="pendingImport = null">Cancel</button>
                </div>
            </div>
        </div>

        <BuilderCommandPalette
            :open="paletteOpen"
            :actions="paletteActions"
            @close="paletteOpen = false"
        />
    </div>
</template>

<style>
:root {
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
    display: flex;
    flex: 1;
    min-height: 0;
}

.builder__diff {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    flex-direction: column;
    background: var(--builder-surface);
}

.builder__diff-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--builder-border);
}

.builder__diff-actions {
    display: flex;
    gap: 8px;
}

.builder__diff-actions button {
    padding: 4px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.builder__diff-actions button:disabled {
    opacity: 0.4;
    cursor: default;
}

.builder__diff-panes {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1px;
    flex: 1;
    min-height: 0;
    background: var(--builder-border);
}

.builder__diff-pane {
    margin: 0;
    display: flex;
    flex-direction: column;
    background: var(--builder-surface);
}

.builder__diff-pane figcaption {
    padding: 4px 12px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.7;
}

.builder__diff-pane iframe {
    flex: 1;
    width: 100%;
    border: 0;
    background: #fff;
}

.builder__canvas {
    position: relative;
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    min-width: 0;
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

.builder__frame.is-inert {
    pointer-events: none;
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

.builder__toolbar {
    position: absolute;
    z-index: 3;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 2px 4px;
    border-radius: 4px;
    background: var(--builder-accent);
    box-shadow: 0 1px 4px rgb(0 0 0 / 35%);
}

.builder__toolbar-kind {
    padding: 0 4px;
    color: #fff;
    font-size: 11px;
    text-transform: capitalize;
}

.builder__toolbar button {
    min-width: 20px;
    padding: 1px 4px;
    border: 0;
    border-radius: 3px;
    background: rgb(255 255 255 / 15%);
    color: #fff;
    font: inherit;
    font-size: 12px;
    line-height: 1.3;
    cursor: pointer;
}

.builder__toolbar button:hover:not(:disabled) {
    background: rgb(255 255 255 / 30%);
}

.builder__toolbar button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.builder__empty {
    position: absolute;
    top: 40%;
    left: 50%;
    transform: translate(-50%, -50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px 28px;
    border: 1px dashed var(--builder-border);
    border-radius: 8px;
    background: color-mix(in srgb, var(--builder-surface) 92%, transparent);
    text-align: center;
}

.builder__empty p {
    margin: 0;
    font-weight: 600;
}

.builder__empty small {
    opacity: 0.65;
}

.builder__empty button {
    padding: 5px 14px;
    border: 0;
    border-radius: 5px;
    background: var(--builder-accent);
    color: #fff;
    font: inherit;
    cursor: pointer;
}

.builder__empty button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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

.builder__modal {
    position: fixed;
    inset: 0;
    z-index: 70;
    display: grid;
    place-items: center;
    background: rgb(0 0 0 / 55%);
}

.builder__modal-box {
    max-width: 420px;
    padding: 18px 20px;
    border: 1px solid var(--builder-border);
    border-radius: 8px;
    background: var(--builder-surface);
}

.builder__modal-box h2 {
    margin: 0 0 8px;
    font-size: 15px;
}

.builder__modal-box p {
    margin: 0 0 14px;
    opacity: 0.8;
}

.builder__modal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.builder__modal-actions button {
    padding: 5px 12px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.builder__modal-actions button:first-child {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
}
</style>
