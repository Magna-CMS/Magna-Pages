<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue'

import { createApi } from './api'
import { CanvasBridge, debounceByKey, type NodeRect } from './bridge'
import BuilderCommandPalette from './components/BuilderCommandPalette.vue'
import BuilderDock from './components/BuilderDock.vue'
import BuilderInspector from './components/BuilderInspector.vue'
import BuilderLayers from './components/BuilderLayers.vue'
import BuilderAddHere from './components/BuilderAddHere.vue'
import BuilderLibrary from './components/BuilderLibrary.vue'
import BuilderNodeMenu from './components/BuilderNodeMenu.vue'
import BuilderPanel from './components/BuilderPanel.vue'
import BuilderTopBar from './components/BuilderTopBar.vue'
import InlineRichEditor from './components/InlineRichEditor.vue'
import { useCanvasDrag, type DropPlacement } from './canvasDrag'
import { exportAsLibraryAsset, nestingMovesFor, sectionOf } from './document/edits'
import { inlineBlockRefusal, inlineTarget, type InlineMode } from './document/inline'
import {
    responsiveStyleOperations,
    withBreakpoint,
    type Breakpoint as ResponsiveBreakpoint,
} from './document/responsive'
import { nodeActions, type NodeAction, type NodeActionKey } from './document/actions'
import {
    blockParents,
    locate,
    parentOf,
    subtreeHeight,
    subtreeIds,
    type NodeKind,
} from './document/locate'
import { needsImportFlow, type DragSource } from './document/placement'
import type { DropRules, ParentInfo } from './dragdrop'
import { classifyFailure } from './resilience'
import { buildActions } from './palette'
import type { BlockNode, SectionNode, StyleControl } from './document/types'
import { useDocumentStore } from './stores/document'
import { useUiStore, type Breakpoint, type Scheme } from './stores/ui'

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

/**
 * Drawer surfaces, fetched the first time one is opened.
 *
 * The dock only renders the open drawer's slot, so these never mount
 * until an editor asks for them — and an editor who never opens Design,
 * Checks, History or Comments never pays for their code
 * (12-BUILDER-REDESIGN §17).
 *
 * The NAVIGATOR is deliberately not among them: it is the guaranteed
 * keyboard editing path, so it is opened constantly and must not wait on
 * a network round trip to appear.
 */
const BuilderDesignPanel = defineAsyncComponent(
    () => import('./components/BuilderDesignPanel.vue'),
)
const BuilderToolsPanel = defineAsyncComponent(() => import('./components/BuilderToolsPanel.vue'))
const BuilderShortcuts = defineAsyncComponent(() => import('./components/BuilderShortcuts.vue'))
const BuilderLibraryBrowser = defineAsyncComponent(
    () => import('./components/BuilderLibraryBrowser.vue'),
)

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

/**
 * Every place a block can land — each column, and each container inside
 * one — with its direct children in DOCUMENT order, so drop math orders by
 * the document rather than by geometry.
 *
 * Derived from the store's document, never from a second tree of its own:
 * the navigator, the canvas and this all read the one document, which is
 * the only way they can agree about what is inside what.
 */
const dropParents = computed<ParentInfo[]>(() =>
    blockParents(store.blocks, store.isContainer).map((parent) => ({
        id: parent.id,
        kind: parent.kind,
        depth: parent.depth,
        blockIds: parent.blocks.map((block) => block.id),
    })),
)

const sectionIds = computed(() => store.sections.map((section) => section.id))

/**
 * What the document will accept for this drag: how much depth the payload
 * itself needs, and which parents it may not enter. Advisory — the server
 * refuses the same things — but it keeps the indicator from being drawn
 * somewhere the drop would then be rejected.
 */
function dropRulesFor(source: DragSource): DropRules {
    if (source.kind !== 'move') {
        return { maxDepth: store.maxBlockDepth, height: 1, forbidden: [] }
    }

    const moved = locate(store.blocks, source.nodeId)
    const node = moved?.kind === 'block' ? (moved.node as BlockNode) : null

    return {
        maxDepth: store.maxBlockDepth,
        height: node ? subtreeHeight(node) : 1,
        // Itself and everything under it: a node cannot contain itself.
        forbidden: node ? subtreeIds(node) : [source.nodeId],
    }
}

const drag = useCanvasDrag({
    rects,
    scrollY,
    sectionIds,
    parents: dropParents,
    stage,
    rulesFor: dropRulesFor,
    onDrop: (source, at) => onDrop(source, at),
})

const bridge = new CanvasBridge({
    onRects: (next, height) => {
        rects.value = next
        canvasHeight.value = height
        // Keep the end gap on the last document section, so the add-here
        // affordance has in-flow room between the content and the footer.
        bridge.endGap(store.sections.at(-1)?.id ?? null, END_GAP)
        // A reload is a new document, and the scheme attribute lived in the
        // old one. Re-applied here rather than on reload, because this is
        // the message that says the new document is ready.
        if (ui.scheme !== 'system') {
            bridge.setScheme(ui.scheme)
        }
    },
    onSelect: (node) => {
        // A click on what is ALREADY selected starts typing, the way every
        // visual builder behaves: the first click chooses the thing, the
        // second one writes in it. Double-click still works, and still
        // reaches the same place — this only means an editor who never
        // discovers the gesture finds the behaviour anyway.
        if (node === store.selectedNode && inlineTargetFor(node) !== null) {
            startInlineEdit(node, false)

            return
        }

        selectNode(node)
    },
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

    onContextMenu: (node, at) => {
        selectNode(node)
        // The bridge reports viewport coordinates INSIDE the frame; the menu
        // is positioned against the parent's viewport, so it needs the
        // stage's offset added.
        const box = stage.value?.getBoundingClientRect()
        contextMenu.value = {
            node,
            at: { x: (box?.left ?? 0) + at.x, y: (box?.top ?? 0) + at.y },
        }
    },

    onEditRequest: (node) => {
        // Double-click: the gesture means "replace these words".
        startInlineEdit(node, true)
    },

    onMeasured: (node, styles) => {
        if (richEdit.value?.node !== node) {
            return
        }

        richEdit.value = { ...richEdit.value, styles }
        bridge.mask(node, true)
    },

    onTextCommit: (node, text) => {
        void commitText(node, text)
    },

    onUneditable: () => {
        // The element carries markup, so a plain-text read of it would
        // flatten that markup into a string. Say so: silence here is what
        // teaches an editor that the canvas does not edit.
        store.error = 'This element holds formatting — edit its text in the panel.'
    },
})

/**
 * Open the inline editor on a node, or say why not.
 *
 * Refusals used to be silent, which is the whole reason the panel felt
 * like the only way to change a word: you click the text, nothing happens,
 * and you stop trying. Every branch below either edits or explains.
 */
function startInlineEdit(node: string, selectAll = true) {
    const target = inlineTargetFor(node)
    if (target === null) {
        const reason = inlineRefusal(node)
        if (reason !== null) {
            store.error = reason
        }

        return
    }

    store.error = null
    store.select(node)

    if (target.mode === 'plain') {
        bridge.setEditable(node, true, selectAll)

        return
    }

    // Rich: the overlay needs the node's typography before it can look
    // like the page, so opening waits for the frame's answer.
    richEdit.value = { node, handle: target.handle, styles: {} }
    bridge.measure(node)
}

/** Why this node cannot be typed over — or null when it can, or when the
 *  click landed on something that was never meant to be typed over at all
 *  (a section, a column) and so deserves no complaint. */
function inlineRefusal(nodeId: string): string | null {
    if (!store.capabilities.content) {
        return 'Editing this page’s content needs the content permission.'
    }
    if (!store.lock.mine) {
        return 'Someone else is editing this page — take over to make changes.'
    }

    const found = locate(store.blocks, nodeId)
    if (!found || found.kind !== 'block') {
        return null
    }

    const definition = store.blockDefinition(String((found.node as { block: string }).block))
    if (!definition) {
        return null
    }

    return inlineBlockRefusal(definition, (found.node as { data?: Record<string, unknown> }).data)
}

/**
 * The field inline editing writes to: the block's first plain-text field.
 * Fields with markup (richtext) are excluded — the bridge edits innerText,
 * and writing that over stored markup would destroy it.
 */
function inlineTargetFor(nodeId: string): { handle: string; mode: InlineMode } | null {
    if (!store.capabilities.content || !store.lock.mine) {
        return null
    }

    const found = locate(store.blocks, nodeId)
    if (!found || found.kind !== 'block') {
        return null
    }

    const definition = store.blockDefinition(String((found.node as { block: string }).block))
    if (!definition) {
        return null
    }

    return inlineTarget(definition, (found.node as { data?: Record<string, unknown> }).data)
}

/** The field the PLAIN path writes to, or null when this is not that. */
function editableField(nodeId: string): string | null {
    const target = inlineTargetFor(nodeId)

    // The plain path reads the element's text back. A rich field read that
    // way would lose its markup, so the two never share a commit.
    return target?.mode === 'plain' ? target.handle : null
}

/** The rich edit in flight: which node, which field, and how it looks. */
const richEdit = ref<{ node: string; handle: string; styles: Record<string, string> } | null>(null)

const richEditRect = computed(() => {
    const rect = rects.value.find((entry) => entry.node === richEdit.value?.node)

    return rect ? { ...rect, top: rect.top - scrollY.value } : null
})

/** The stored markup the overlay opens with. */
const richEditHtml = computed<string>(() => {
    if (!richEdit.value) {
        return ''
    }

    const found = locate(store.blocks, richEdit.value.node)
    const value = (found?.node as { data?: Record<string, unknown> } | undefined)?.data?.[
        richEdit.value.handle
    ]

    return typeof value === 'string' ? value : ''
})

function closeRichEdit() {
    if (richEdit.value) {
        bridge.mask(richEdit.value.node, false)
    }
    richEdit.value = null
}

async function onRichCommit(html: string) {
    const editing = richEdit.value
    closeRichEdit()

    if (!editing) {
        return
    }

    const found = locate(store.blocks, editing.node)
    if (!found) {
        return
    }

    await store.edit(api, 'Edit text', [
        { op: 'replace', path: `${found.pointer}/data/${editing.handle}`, value: html },
    ])

    // Re-render either way: on success the canvas shows the stored
    // (sanitized) markup, which is what the server actually kept; on
    // refusal it snaps back to the document's truth.
    refreshFragment(editing.node)
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
    const inParent = 'parent' in at

    if (source.kind === 'move') {
        if (inParent && (await store.moveBlock(api, source.nodeId, at.parent, at.index))) {
            reloadCanvas()
        }

        return
    }

    if (source.kind === 'new') {
        if (inParent && (await store.addBlock(api, at.parent, source.handle, at.index))) {
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

/** The style controls the server offers for whatever kind is selected. */
/**
 * The style vocabulary the inspector draws for what is selected.
 *
 * A container reads a wider one than a plain block — it arranges what it
 * holds — and the server decides which, from the block's definition. The
 * client only asks; a list of container handles here would be the second
 * source of truth the descriptor table exists to avoid.
 */
const styleControlsForSelection = computed<StyleControl[]>(() => {
    if (!selected.value) {
        return []
    }

    const kind =
        selected.value.kind === 'block' && store.isContainer(selected.value.node as BlockNode)
            ? 'container'
            : selected.value.kind

    return store.styleControls[kind] ?? []
})

/**
 * The device preview decides which breakpoint a style edit writes. Desktop
 * is the document's `base`: the value every width starts from.
 */
const styleBreakpoint = computed<ResponsiveBreakpoint>(() =>
    ui.breakpoint === 'desktop' ? 'base' : ui.breakpoint,
)

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

    // One field is one gesture: typing into it, or dragging its picker,
    // collapses to a single undo step rather than one per keystroke.
    const ok = await store.edit(
        api,
        `Edit ${handle}`,
        [{ op: 'replace', path: `${pointer}/data/${handle}`, value }],
        `field:${pointer}:${handle}`,
    )

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
 * Where a new block would go — the selected container, the selected column,
 * or whatever holds the selected block, so "select a heading, add a
 * paragraph" lands where the user is looking rather than at the end of the
 * page. The rule lives in the store, because paste needs the same answer.
 */
const targetParent = computed<string | null>(() => store.insertionParent)

async function onAddBlock(handle: string) {
    if (targetParent.value && (await store.addBlock(api, targetParent.value, handle))) {
        // A placed element is one the editor wants to fill in next.
        ui.inspect('content')
        reloadCanvas()
    }
}

/**
 * How much in-flow room the bridge reserves after the last section — the
 * body of the page, not the void under the footer, which is where
 * Elementor puts it and where an editor's eye already is.
 */
const END_GAP = 150

/**
 * Where the add-here affordance sits: inside the gap the bridge reserves
 * between the last document section and whatever the theme renders next.
 */
const pageEndTop = computed<number | null>(() => {
    if (!store.loaded || store.sections.length === 0) {
        return null
    }

    const bottoms = rects.value
        .filter((rect) => rect.kind === 'section')
        .map((rect) => rect.top + rect.height)

    return bottoms.length > 0 ? Math.max(...bottoms) : null
})

/** The full-surface library browser, with live previews. */
const libraryOpen = ref(false)

function onBrowseCloud() {
    libraryOpen.value = true
}

/** Insert from the browser: close it, then the ordinary insert flow. */
async function onLibraryInsert(slug: string) {
    libraryOpen.value = false
    await onInsertLibrary(slug)
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

/**
 * Write one style key on the selected node.
 *
 * JSON Patch `add` needs its parent to exist, so a node styled for the
 * first time writes the whole `style` object; after that each key is its
 * own operation. Clearing a value removes the key rather than storing an
 * empty string — an absent key is what "not styled" means everywhere else
 * in the document, and the renderer treats the two differently.
 */
async function onSetStyle(pointer: string, key: string, value: string) {
    await writeStyle(pointer, 'style', key, value)
}

/**
 * The page's own style.
 *
 * Its own write path because the page is not a node: there is no pointer
 * to patch and no document position to hold. The canvas is reloaded
 * afterwards rather than patched in place, since the declaration lands on
 * `body` and there is no fragment that could carry it.
 */
async function onPageStyle(key: string, value: string) {
    const style = { ...((store.pageSettings.style as Record<string, unknown> | undefined) ?? {}) }

    // The device preview decides the breakpoint, exactly as it does for a
    // node. Clearing REMOVES the key rather than storing an empty string,
    // because absent is what "not set" means to the renderer.
    const next = withBreakpoint(style[key], styleBreakpoint.value, value)
    if (next === undefined) {
        delete style[key]
    } else {
        style[key] = next
    }

    // Built from the whole settings object, not from the style alone: a
    // page that has chosen a header must not lose it to a background edit.
    const settings = { ...store.pageSettings }
    if (Object.keys(style).length === 0) {
        delete settings.style
    } else {
        settings.style = style
    }

    store.pageSettings = settings

    try {
        await api.putSettings(settings)
        await reloadCanvas()
    } catch (error) {
        store.error = error instanceof Error ? error.message : String(error)
    }
}

/**
 * Which header or footer this page uses.
 *
 * An empty id hands the page back to the site default rather than storing
 * "none": a two-level chain has no third state, and a page with no chrome
 * is expressed by the site having none, not by every page saying so.
 */
async function onPageChrome(role: 'header' | 'footer', id: string) {
    const settings = { ...store.pageSettings }
    if (id === '') {
        delete settings[role]
    } else {
        settings[role] = id
    }

    store.pageSettings = settings

    try {
        await api.putSettings(settings)
        await reloadCanvas()
    } catch (error) {
        store.error = error instanceof Error ? error.message : String(error)
    }
}

/** Row layout writes to `settings.row`, its own set beside `settings.style`. */
async function onSetRowStyle(pointer: string, key: string, value: string) {
    await writeStyle(pointer, 'row', key, value)
}

/**
 * One style write, whichever set it lands in. The device preview decides
 * the breakpoint; clearing removes the key rather than storing an empty
 * string, because absent is what "not set" means to the renderer.
 */
async function writeStyle(pointer: string, settingsKey: string, key: string, value: string) {
    const settings = (selected.value?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const operations = responsiveStyleOperations(
        settings?.[settingsKey],
        pointer,
        key,
        styleBreakpoint.value,
        value,
        settingsKey,
    )

    if (operations.length === 0) {
        return
    }

    // One control at one breakpoint is one gesture — dragging a colour
    // picker must not bury the rest of the history under its own steps.
    const gesture = `style:${pointer}:${settingsKey}:${key}:${styleBreakpoint.value}`

    if (await store.edit(api, `Style ${key}`, operations, gesture)) {
        // Styles land on wrappers the per-node fragment loop does not
        // re-render.
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

/**
 * Where a node sits among its siblings: sections among sections, blocks
 * among their column's blocks. Both the move actions and their enabled
 * state need it.
 */
function siblingsOf(nodeId: string): { list: string[]; index: number; parent: string | null } {
    const section = store.sections.findIndex((entry) => entry.id === nodeId)
    if (section >= 0) {
        return {
            list: store.sections.map((entry) => entry.id),
            index: section,
            parent: null,
        }
    }

    // Blocks reorder among the blocks of whatever holds them — a column, or
    // a container. One lookup, so a nested block is as movable from the
    // keyboard as a top-level one.
    const parent = parentOf(store.blocks, nodeId)
    if (parent === null) {
        return { list: [], index: -1, parent: null }
    }

    const list = parent.blocks.map((block) => block.id)

    return { list, index: list.indexOf(nodeId), parent: parent.id }
}

const contextMenu = ref<{ node: string; at: { x: number; y: number } } | null>(null)

/** Which way this block could be nested, from where it currently sits. */
function nestingFor(nodeId: string) {
    return nestingMovesFor(store.blocks, nodeId, store.isContainer, store.maxBlockDepth)
}

/**
 * What a node offers right now.
 *
 * One builder for both menus: the canvas right-click and the navigator row
 * promise to offer the same actions, and two copies of this context is how
 * that promise quietly stops being true.
 */
function actionsForNode(nodeId: string, kind: NodeKind): NodeAction[] {
    const position = siblingsOf(nodeId)
    const nesting = kind === 'block' ? nestingFor(nodeId) : { into: null, out: null }

    return nodeActions({
        kind,
        canStructure: store.capabilities.structure,
        canContent: store.capabilities.content,
        canStyle: store.capabilities.style,
        holdsLock: store.lock.mine,
        hasClipboard: store.clipboard !== null,
        hasStyles: store.styleClipboard !== null,
        isFirst: position.index <= 0,
        isLast: position.index < 0 || position.index === position.list.length - 1,
        canMoveInto: nesting.into !== null,
        canMoveOut: nesting.out !== null,
    })
}

/** Right-click on the canvas: the node's actions, where the pointer is. */
const menuActions = computed<NodeAction[]>(() =>
    selected.value
        ? actionsForNode(String((selected.value.node as { id: string }).id), selected.value.kind)
        : [],
)

/**
 * A navigator row acts on ITS node, which may not be the selected one —
 * so select first, then run the action through the one dispatcher.
 */
async function onNavigatorAction(nodeId: string, key: NodeActionKey) {
    if (store.selectedNode !== nodeId) {
        selectNode(nodeId)
    }

    await onNodeAction(key)
}

async function onNodeAction(key: NodeActionKey) {
    contextMenu.value = null

    const node = store.selectedNode
    if (node === null) {
        return
    }

    if (key === 'copy') {
        store.copyNode(node)

        return
    }
    if (key === 'rename') {
        const found = locate(store.blocks, node)
        const current = (found?.node as { settings?: { label?: unknown } } | undefined)?.settings?.label

        const name = window.prompt(
            'Name this layer',
            typeof current === 'string' ? current : '',
        )
        if (name !== null) {
            await store.renameNode(api, node, name)
        }

        return
    }
    if (key === 'copyStyles') {
        store.copyStyles(node)

        return
    }
    if (key === 'pasteStyles') {
        if (await store.pasteStyles(api, node)) {
            reloadCanvas()
        }

        return
    }
    if (key === 'paste') {
        if (await store.pasteNode(api)) {
            reloadCanvas()
        }

        return
    }
    if (key === 'duplicate') {
        await onDuplicate()

        return
    }
    if (key === 'delete') {
        await onDelete()

        return
    }
    if (key === 'savePattern') {
        await onSavePattern()

        return
    }
    if (key === 'moveInto' || key === 'moveOut') {
        await onNestNode(node, key === 'moveInto' ? 'into' : 'out')

        return
    }

    await onMoveNode(node, key === 'moveUp' ? -1 : 1)
}

/**
 * Nest a block one level in, or lift it one level out — the keyboard
 * equivalent of dragging it into a container, so nesting is not a feature
 * only the people who can drag get to use (12-BUILDER-REDESIGN §18).
 */
async function onNestNode(nodeId: string, direction: 'into' | 'out') {
    const target = nestingFor(nodeId)[direction]
    if (target === null) {
        return
    }

    if (await store.moveBlock(api, nodeId, target.parent, target.index)) {
        reloadCanvas()
    }
}

/**
 * Move a node one place among its siblings — the keyboard equivalent of
 * dragging it, and the reason the navigator is a complete editing path.
 */
async function onMoveNode(nodeId: string, delta: number) {
    const position = siblingsOf(nodeId)
    if (position.index < 0) {
        return
    }

    if (position.parent === null) {
        await onMoveSection(delta)

        return
    }

    // The index is counted before the node is removed, so travelling
    // forward aims one past the neighbour it is passing.
    const target = delta < 0 ? position.index - 1 : position.index + 2
    if (target < 0 || target > position.list.length) {
        return
    }

    if (await store.moveBlock(api, nodeId, position.parent, target)) {
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
        // Shown only when there IS something to type over, so the button is
        // never an invitation that does nothing.
        canEditText:
            kind === 'block' &&
            store.selectedNode !== null &&
            inlineTargetFor(store.selectedNode) !== null,
        canDelete: structural && kind !== null,
        canMove: structural && kind === 'section',
    }
})

/** Command palette, and the shortcut list behind `?`. */
const paletteOpen = ref(false)
const shortcutsOpen = ref(false)

const paletteActions = computed(() =>
    buildActions({
        capabilities: store.capabilities,
        lockMine: store.lock.mine,
        hasSelection: store.selectedNode !== null,
        targetParent: targetParent.value,
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

    if (event.key === 'Escape') {
        if (libraryOpen.value) {
            libraryOpen.value = false

            return
        }
        if (shortcutsOpen.value) {
            shortcutsOpen.value = false

            return
        }
        if (contextMenu.value) {
            contextMenu.value = null

            return
        }

        // Last rung: step out of the selection. Escape has always closed
        // the innermost thing that was open, and a selection is the
        // innermost thing once the overlays are gone — it is also now the
        // only way back to the PAGE's own settings, which is what the
        // panel shows when nothing is selected.
        if (store.selectedNode) {
            selectNode(null)
        }
    }

    // `?` is what people try, and it is only a question mark when nothing
    // is being typed into — the guard above already returned for that.
    if (event.key === '?') {
        event.preventDefault()
        shortcutsOpen.value = !shortcutsOpen.value

        return
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'c' && store.selectedNode) {
        store.copyNode(store.selectedNode)

        return
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v') {
        event.preventDefault()
        void onNodeAction('paste')

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

/**
 * Show the canvas in one reading of the palette.
 *
 * Re-applied after every canvas reload, because the attribute lives in the
 * frame and a reload is a new document — without that, previewing dark and
 * then editing anything would silently drop back to light.
 */
function onScheme(scheme: Scheme) {
    ui.scheme = scheme
    bridge.setScheme(scheme)
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
    // end of whatever the selection sits in. A drag supplies an exact one.
    const at = targetParent.value ? { parent: targetParent.value } : undefined

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
    // Click-to-insert: a block pattern joins whatever the selection sits
    // in, a section pattern appends. A drag supplies an exact target.
    const at = targetParent.value ? { parent: targetParent.value } : undefined

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
                        :target-parent="targetParent"
                        :capabilities="store.capabilities"
                        :patterns="store.patterns"
                        :library-assets="store.libraryAssets"
                        :library-collections="store.libraryCollections"
                        @add="onAddBlock"
                        @add-section="onAddSection"
                        @insert-pattern="onInsertPattern"
                        @insert-library="onInsertLibrary"
                        @drag-start="drag.pressInPanel"
                        @open-browser="libraryOpen = true"
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
                    :display-conditions="store.displayConditions"
                        :layout-section="layoutSection"
                        :style-controls="styleControlsForSelection"
                        :style-breakpoint="styleBreakpoint"
                        :row-controls="selected?.kind === 'section' ? (store.styleControls.row ?? []) : []"
                        @edit="onFieldEdit"
                        @edit-setting="onSettingEdit"
                        @add-column="onAddColumn"
                        @remove-column="onRemoveColumn"
                        @set-spans="onSetSpans"
                        @set-style="onSetStyle"
                        :chrome-choices="store.chrome"
                        :chrome-used="{
                            header: (store.pageSettings.header as string) ?? '',
                            footer: (store.pageSettings.footer as string) ?? '',
                        }"
                        @page-chrome="onPageChrome"
                        :page-controls="store.styleControls.page ?? []"
                        :page-style="(store.pageSettings.style as Record<string, unknown>) ?? {}"
                        @page-style="onPageStyle"
                        @set-row-style="onSetRowStyle"
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

                    <!--
                        Which reading of the palette the canvas shows. A
                        preview, not a setting: the page carries both
                        readings in one stylesheet, so this chooses what is
                        on screen and changes nothing about the document.
                        Designing for dark without being able to see it is
                        designing blind.
                    -->
                    <div class="builder__schemes" role="group" aria-label="Preview colour scheme">
                        <button
                            v-for="scheme in (['system', 'light', 'dark'] as const)"
                            :key="scheme"
                            type="button"
                            class="builder__viewport"
                            :class="{ 'is-active': ui.scheme === scheme }"
                            :title="`Preview in ${scheme === 'system' ? 'the visitor’s preference' : scheme}`"
                            @click="onScheme(scheme)"
                        >
                            {{ scheme }}
                        </button>
                    </div>
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
                            v-if="toolbar.canEditText"
                            type="button"
                            title="Edit text (or click the text again)"
                            aria-label="Edit text in place"
                            @click="store.selectedNode && startInlineEdit(store.selectedNode)"
                        >
                            ✎
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

                    <InlineRichEditor
                        v-if="richEdit && richEditRect"
                        :key="richEdit.node"
                        :rect="richEditRect"
                        :html="richEditHtml"
                        :styles="richEdit.styles"
                        :can-edit="store.capabilities.content && store.lock.mine"
                        @commit="onRichCommit"
                        @cancel="closeRichEdit"
                    />

                    <!-- An empty page with no instructions is where a first
                         session stalls; the way forward is the first step of
                         the workflow, not a decoration. -->
                    <div v-if="store.loaded && store.sections.length === 0" class="builder__empty">
                        <BuilderAddHere
                            :can-structure="store.capabilities.structure"
                            @add-section="onAddSection"
                            @browse="onBrowseCloud"
                        />
                    </div>

                    <!-- And where content ends, the way forward continues:
                         the same affordance after the last section. In its
                         own clipped layer rather than the overlay, because
                         the overlay is aria-hidden and these are controls. -->
                    <div v-if="pageEndTop !== null && !drag.active.value" class="builder__endzone">
                        <BuilderAddHere
                            class="builder__endzone-item"
                            :style="{ top: `${pageEndTop - scrollY + 14}px` }"
                            :can-structure="store.capabilities.structure"
                            @add-section="onAddSection"
                            @browse="onBrowseCloud"
                        />
                    </div>
                </div>
            </main>
        </div>

        <BuilderDock :comment-count="comments?.filter((entry) => !entry.resolved).length ?? null">
            <template #layers>
                <BuilderLayers
                    :sections="store.sections"
                    :selected="store.selectedNode"
                    :actions-for="actionsForNode"
                    @select="selectNode($event)"
                    @act="onNavigatorAction"
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
                    :groups="['checks']"
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

            <template #history>
                <BuilderToolsPanel
                    :groups="['history']"
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

        <!-- Right-click actions. The backdrop closes it; Escape does too. -->
        <div v-if="contextMenu" class="builder__menubackdrop" @pointerdown="contextMenu = null" />
        <BuilderNodeMenu
            v-if="contextMenu"
            :at="contextMenu.at"
            :actions="menuActions"
            :label="selectionLabel ?? 'Node'"
            @pick="onNodeAction($event as NodeActionKey)"
            @close="contextMenu = null"
        />

        <BuilderShortcuts v-if="shortcutsOpen" @close="shortcutsOpen = false" />

        <BuilderLibraryBrowser
            v-if="libraryOpen"
            :assets="store.libraryAssets"
            :collections="store.libraryCollections"
            :preview-url="api.libraryPreviewUrl"
            :can-structure="store.capabilities.structure"
            @insert="onLibraryInsert"
            @close="libraryOpen = false"
        />

        <BuilderCommandPalette
            :open="paletteOpen"
            :actions="paletteActions"
            @close="paletteOpen = false"
        />
    </div>
</template>

<style>
/*
 * The builder's own palette, checked against WCAG AA rather than eyeballed.
 *
 * The accent carries white text (a selected navigator row, the inline
 * toolbar's active button), so it is bound by the 4.5:1 text rule, not the
 * 3:1 one — the previous #3d8bfd read as 3.33:1 both ways and failed. This
 * one measures 5.17:1 under white and 3.50:1 against the surface, so it
 * still works as a focus ring and a selection indicator.
 *
 * --builder-border is deliberately quiet at 1.28:1: it separates panels, it
 * does not report state or bound a control, and 1.4.11 asks for contrast on
 * the things that do.
 */
:root {
    --builder-accent: #2563eb;
    --builder-surface: #14161d;
    --builder-border: #272b36;
    --builder-text: #e7e9ee;
    /* Visible enough to read as a control against the panel, quiet enough
       not to compete with the content beside it. No hover variant: the
       only way to express one is `*:hover`, which asks the engine to
       recalculate styles for every element under the pointer. */
    --builder-scroll: #454c5e;
}

/*
 * Scrollbars, for every surface in the builder that scrolls.
 *
 * The platform default is a light-mode widget: a wide grey trough with
 * arrow buttons, drawn over a dark panel. It reads as a seam down the
 * middle of the interface rather than as a control, and at the panel's
 * width it costs real room.
 *
 * The standard properties are the primary path. Setting EITHER of them
 * makes Chromium ignore ::-webkit-scrollbar entirely, so the two cannot be
 * combined — declaring `scrollbar-width` beside a set of pseudo-element
 * rules silently discards the pseudo-elements and leaves the default bar,
 * which is exactly the trap this comment exists to mark.
 */
* {
    scrollbar-width: thin;
    scrollbar-color: var(--builder-scroll) transparent;
}

/*
 * Engines with no `scrollbar-color` — Safari before 18.2 — get the older
 * pseudo-elements instead. Fenced, because on an engine that has both this
 * block would be the one thrown away.
 */
@supports not (scrollbar-color: auto) {
    ::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    ::-webkit-scrollbar-track {
        background: transparent;
    }

    ::-webkit-scrollbar-thumb {
        /* Inset by a transparent border so the thumb reads as a floating
           pill rather than a filled channel, and never smaller than a
           target worth aiming at. */
        background: var(--builder-scroll);
        background-clip: padding-box;
        border: 2px solid transparent;
        border-radius: 99px;
        min-height: 32px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: var(--builder-scroll);
        background-clip: padding-box;
    }

    ::-webkit-scrollbar-button,
    ::-webkit-scrollbar-corner {
        display: none;
    }
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

.builder__schemes {
    display: flex;
    gap: 3px;
    /* Set apart from the device buttons: they answer different questions,
       and a single run of six buttons reads as one choice of six. */
    margin-left: 14px;
    padding-left: 14px;
    border-left: 1px solid var(--builder-border);
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
    top: 38%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: min(640px, calc(100% - 48px));
}

.builder__endzone {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
}

.builder__endzone-item {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    width: min(640px, calc(100% - 48px));
    pointer-events: auto;
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

.builder__menubackdrop {
    position: fixed;
    inset: 0;
    z-index: 79;
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
