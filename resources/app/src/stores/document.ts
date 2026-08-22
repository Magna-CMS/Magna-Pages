import { defineStore } from 'pinia'

import type { BuilderApi } from '../api'
import {
    addColumn,
    appendSection,
    blockFrom,
    duplicateNode,
    emptySection,
    insertBlock,
    insertSectionsAt,
    moveNode,
    moveSection,
    pasteColumnOperations,
    removeColumn,
    removeNode,
    setSpans,
    withFreshIds,
} from '../document/edits'
import { locate, parentOf } from '../document/locate'
import { applyPatch } from '../document/patch'
import { classifyFailure, loadQueue, persistQueue, type QueuedBatch } from '../resilience'
import type {
    ApprovalState,
    BlockDefinition,
    BlockDocument,
    BlockNode,
    Capabilities,
    ChromeChoice,
    DisplayConditionOption,
    LockState,
    PatchOperation,
    SectionNode,
    StyleControls,
} from '../document/types'
import { sectionsOf, sectionsPointer } from '../document/types'

/**
 * The document store: the single source of truth the canvas projects.
 *
 * An edit lands locally first so typing feels instant, then goes to the
 * server. If the server refuses — a permission the UI mis-modelled, a
 * document that would end up invalid — the local change is rolled back with
 * the inverse batch that was computed when it was applied. The user sees
 * their edit undone and the server's reason, rather than a canvas that
 * disagrees with what was actually saved.
 *
 * Undo history is the same inverse mechanism, kept to a bounded number of
 * steps and grouped per gesture.
 */

const HISTORY_LIMIT = 100

/** Shared across pages of the same site, so paste crosses documents. */
const CLIPBOARD_KEY = 'magna-builder-clipboard'

export interface ClipboardEntry {
    kind: 'section' | 'column' | 'block'
    node: Record<string, unknown>
}

/**
 * Where an inserted instance goes.
 *
 * `parent` is whatever holds blocks — a column, or a container block. The
 * two are the same decision to every caller, so they are one shape here;
 * `sectionIndex` is the other decision entirely, a position between
 * sections at the root of the document. Omitting `index` appends, which is
 * what click-to-insert means; a drag always knows its exact position.
 */
export type Placement = { parent: string; index?: number } | { sectionIndex: number }

/** Nesting depth the server allows when the bootstrap payload is silent. */
const DEFAULT_MAX_BLOCK_DEPTH = 6

/** Whether a block holds other blocks, according to the shipped registry. */
function holdsChildren(registry: BlockDefinition[], block: BlockNode): boolean {
    return registry.find((definition) => definition.handle === block.block)?.container === true
}

/**
 * What became of one batch.
 *
 * `queued` is not failure: the operations are kept and replayed, so the
 * local document stays as the editor left it. Only `refused` means the
 * server answered and said no, and only that puts the document back.
 */
export type SendOutcome = 'ok' | 'queued' | 'refused'

interface HistoryEntry {
    label: string
    undo: PatchOperation[]
    redo: PatchOperation[]
    /**
     * What continuous gesture this entry belongs to, if any. Two edits
     * carrying the same key close together are one gesture — see
     * `pushHistory`.
     */
    key?: string
    /** When it was pushed, for the coalescing window. */
    at: number
}

/**
 * How long after an edit a same-key edit still counts as the same gesture.
 *
 * Long enough to cover dragging a colour picker or typing a length, short
 * enough that coming back to a field a moment later is its own undo step.
 */
const COALESCE_WINDOW_MS = 700

export interface PatternSummary {
    id: string
    name: string
    kind: string
}

export interface LibraryAssetSummary {
    slug: string
    name: string
    kind: string
    /** Which chrome a part is, when it is one: 'header' | 'footer'. */
    role?: string | null
    description: string | null
    missingBlocks: string[]
    downloads: number
}

export interface LibraryCollectionSummary {
    slug: string
    name: string
    publisher: string
    assetCount: number
}

interface State {
    pageId: string
    title: string
    status: string
    publicUrl: string | null
    patterns: PatternSummary[]
    libraryAssets: LibraryAssetSummary[]
    libraryCollections: LibraryCollectionSummary[]
    blocks: BlockDocument
    registry: BlockDefinition[]
    /** How deep blocks may nest, as the server counts it. */
    maxBlockDepth: number
    tokens: Record<string, string>
    capabilities: Capabilities
    selectedNode: string | null
    lock: LockState
    approval: ApprovalState | null
    bindingSources: Record<string, string>
    styleControls: StyleControls
    displayConditions: DisplayConditionOption[]
    icons: Record<string, string>
    /** The page's own settings — its ground, stored beside the document. */
    pageSettings: Record<string, unknown>
    chrome: { header: ChromeChoice[]; footer: ChromeChoice[] }
    undoStack: HistoryEntry[]
    redoStack: HistoryEntry[]
    saving: boolean
    error: string | null
    loaded: boolean
    /** Batches accepted locally but not yet acknowledged by the server. */
    sendQueue: QueuedBatch[]
    replaying: boolean
    clipboard: ClipboardEntry | null
    /** A copied style set, kept apart from the node clipboard. */
    styleClipboard: Record<string, unknown> | null
}

export const useDocumentStore = defineStore('document', {
    state: (): State => ({
        pageId: '',
        title: '',
        status: '',
        publicUrl: null,
        patterns: [],
        libraryAssets: [],
        libraryCollections: [],
        blocks: [],
        registry: [],
        maxBlockDepth: DEFAULT_MAX_BLOCK_DEPTH,
        tokens: {},
        capabilities: { content: false, structure: false, style: false, publish: false },
        selectedNode: null,
        lock: { mine: false, holder: null },
        approval: null,
        bindingSources: {},
        styleControls: {},
        displayConditions: [],
        icons: {},
        pageSettings: {},
        chrome: { header: [], footer: [] },
        undoStack: [],
        redoStack: [],
        saving: false,
        error: null,
        loaded: false,
        sendQueue: [],
        replaying: false,
        clipboard: null,
        styleClipboard: null,
    }),

    getters: {
        sections: (state): SectionNode[] => sectionsOf(state.blocks),
        canUndo: (state): boolean => state.undoStack.length > 0,
        canRedo: (state): boolean => state.redoStack.length > 0,
        blockDefinition: (state) => {
            return (handle: string): BlockDefinition | undefined =>
                state.registry.find((definition) => definition.handle === handle)
        },

        /**
         * Whether a block holds other blocks. The registry answers it, so
         * `container` is never inferred from a handle — a plugin's own
         * layout block nests on the same terms core's does.
         */
        isContainer: (state) => {
            return (block: BlockNode): boolean => holdsChildren(state.registry, block)
        },

        /**
         * Where a click-to-insert puts a block, given what is selected.
         *
         * A selected CONTAINER is the thing being filled, so the block goes
         * inside it; anything else gets a sibling. A selected section
         * targets its first column, so "add a section, then add an element"
         * works without a second click into the column — the approved flow
         * is Add Section → Choose Columns → Drag Element, and making the
         * middle step mandatory would break it.
         */
        insertionParent(state): string | null {
            if (state.selectedNode === null) {
                return null
            }

            const found = locate(state.blocks, state.selectedNode)
            if (!found) {
                return null
            }

            if (found.kind === 'column') {
                return state.selectedNode
            }

            if (found.kind === 'section') {
                const columns = (found.node as SectionNode).columns ?? []

                return columns.length > 0 ? String(columns[0].id) : null
            }

            const block = found.node as BlockNode

            return holdsChildren(state.registry, block)
                ? block.id
                : (parentOf(state.blocks, block.id)?.id ?? null)
        },
    },

    actions: {
        async load(api: BuilderApi): Promise<void> {
            const payload = await api.bootstrap()

            this.pageId = payload.document.id
            this.title = payload.document.title
            this.status = payload.document.status
            this.blocks = payload.document.blocks
            this.registry = payload.registry
            this.maxBlockDepth = payload.maxBlockDepth ?? DEFAULT_MAX_BLOCK_DEPTH
            this.tokens = payload.tokens
            this.capabilities = payload.capabilities
            this.lock = payload.lock ?? { mine: true, holder: null }
            this.approval = payload.approval ?? null
            this.bindingSources = payload.bindingSources ?? {}
            this.styleControls = payload.styleControls ?? {}
            this.displayConditions = payload.displayConditions ?? []
            this.icons = payload.icons ?? {}
            this.pageSettings = payload.document?.settings ?? {}
            this.chrome = payload.chrome ?? { header: [], footer: [] }
            this.loaded = true
        },

        async requestPublish(api: BuilderApi, note: string | null): Promise<boolean> {
            this.error = null
            try {
                const result = await api.requestPublish(note)
                this.approval = { id: result.request.id, requested_at: null }

                return true
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }
        },

        async loadPatterns(api: BuilderApi): Promise<void> {
            try {
                this.patterns = (await api.patterns()).patterns
            } catch {
                // The library failing to list must not take the builder down.
                this.patterns = []
            }
        },

        /** Save the currently selected section or block as a pattern. */
        async saveAsPattern(api: BuilderApi, name: string): Promise<boolean> {
            if (!this.selectedNode) {
                return false
            }

            const found = locate(this.blocks, this.selectedNode)
            if (!found || found.kind === 'column') {
                this.error = 'Select a section or a block to save as a pattern.'

                return false
            }

            this.error = null
            try {
                await api.savePattern(name, found.kind, found.node)
                await this.loadPatterns(api)

                return true
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }
        },

        async loadLibrary(api: BuilderApi): Promise<void> {
            try {
                const library = await api.library()
                this.libraryAssets = library.assets
                this.libraryCollections = library.collections
            } catch {
                // A hub outage empties the panel; the builder is unaffected.
                this.libraryAssets = []
                this.libraryCollections = []
            }
        },

        /**
         * Insert a cloud-library asset at a placement the caller resolved
         * from the asset's kind (see document/placement.ts).
         *
         * `at` omitted means "wherever this kind goes by default" —
         * appended — which is what click-to-insert and the keyboard path
         * do. A drag supplies an exact target.
         */
        async insertLibraryAsset(
            api: BuilderApi,
            slug: string,
            at?: Placement,
        ): Promise<boolean> {
            this.error = null

            let instance: { kind: string; node: Record<string, unknown> }
            try {
                instance = await api.libraryInstance(slug)
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            return this.placeInstance(api, 'Insert from library', instance, at)
        },

        /**
         * Place a fetched instance — library asset or pattern — by shape.
         *
         * A block-shaped instance joins a column; a section-shaped one sits
         * between sections; a list of sections splices in order. The server
         * already handed back fresh ids, so nothing here can collide with
         * what is on the page.
         */
        async placeInstance(
            api: BuilderApi,
            label: string,
            instance: { kind: string; node: Record<string, unknown> },
            at?: Placement,
        ): Promise<boolean> {
            // Shape decides, not the label: a node carrying a `block`
            // handle is a block wherever it came from, and a kind string
            // from the hub is advisory.
            const isBlock =
                !Array.isArray(instance.node) && typeof instance.node.block === 'string'

            if (isBlock) {
                const parent = at && 'parent' in at ? at.parent : this.insertionParent

                if (parent === null) {
                    this.error = 'Choose a column for this block.'

                    return false
                }

                const operations = insertBlock(
                    this.blocks,
                    parent,
                    instance.node as never,
                    at && 'parent' in at ? at.index : undefined,
                )

                return operations ? this.edit(api, label, operations) : false
            }

            const sections = Array.isArray(instance.node)
                ? (instance.node as unknown as Record<string, unknown>[])
                : [instance.node]

            const operations =
                at && 'sectionIndex' in at
                    ? insertSectionsAt(this.blocks, sections as never, at.sectionIndex)
                    : sections.flatMap((node) => appendSection(this.blocks, node as never))

            return this.edit(api, label, operations)
        },

        /**
         * Import a whole page asset. Deliberately not a drop: replacing a
         * document is a decision, so the caller confirms first and states
         * which mode it chose.
         */
        async importPageAsset(
            api: BuilderApi,
            slug: string,
            mode: 'replace' | 'append',
        ): Promise<boolean> {
            this.error = null

            let instance: { kind: string; node: Record<string, unknown> }
            try {
                instance = await api.libraryInstance(slug)
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            const incoming = (
                Array.isArray(instance.node) ? instance.node : [instance.node]
            ) as unknown as Record<string, unknown>[]

            if (mode === 'append') {
                return this.edit(
                    api,
                    'Import page',
                    incoming.flatMap((node) => appendSection(this.blocks, node as never)),
                )
            }

            // Replace: drop every existing section, then add the incoming
            // ones. Removals run back-to-front so earlier indexes stay
            // valid as the list shrinks.
            const prefix = sectionsPointer(this.blocks)
            const existing = sectionsOf(this.blocks)
            const operations: PatchOperation[] = [
                ...existing.map(
                    (_, index): PatchOperation => ({
                        op: 'remove',
                        path: `${prefix}/${existing.length - 1 - index}`,
                    }),
                ),
                ...incoming.map(
                    (node): PatchOperation => ({ op: 'add', path: `${prefix}/-`, value: node }),
                ),
            ]

            const ok = await this.edit(api, 'Import page', operations)
            if (ok) {
                this.select(null)
            }

            return ok
        },

        /** Insert a pattern instance: sections between sections, blocks into a column. */
        async insertPattern(
            api: BuilderApi,
            patternId: string,
            at?: Placement,
        ): Promise<boolean> {
            this.error = null

            let instance: { kind: string; node: Record<string, unknown> }
            try {
                instance = await api.patternInstance(patternId)
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            return this.placeInstance(api, 'Insert pattern', instance, at)
        },

        async publish(api: BuilderApi): Promise<boolean> {
            this.error = null
            this.saving = true
            try {
                const result = await api.publish()
                this.status = result.status
                this.publicUrl = result.url

                return true
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            } finally {
                this.saving = false
            }
        },

        async takeOver(api: BuilderApi): Promise<void> {
            try {
                const result = await api.takeOver()
                this.lock = { mine: result.lock.mine, holder: null }
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)
            }
        },

        /** Called when a heartbeat or write reports the lock is gone. */
        lockLost(holderName: string | null): void {
            this.lock = {
                mine: false,
                holder: holderName === null ? null : { id: '', name: holderName },
            }
        },

        /**
         * Apply one gesture's worth of operations: locally now, on the
         * server next, rolled back if the server refuses.
         *
         * `coalesceKey` names a CONTINUOUS gesture — one field, one style
         * key. Successive edits carrying the same key fold into a single
         * undo step, so dragging a colour picker is one thing to undo
         * rather than forty (12-BUILDER-REDESIGN §17).
         */
        async edit(
            api: BuilderApi,
            label: string,
            operations: PatchOperation[],
            coalesceKey?: string,
        ): Promise<boolean> {
            this.error = null

            let inverse: PatchOperation[]
            try {
                const applied = applyPatch(this.blocks, operations)
                this.blocks = applied.document
                inverse = applied.inverse
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            // The stack as it was, so a refusal can put it back. Popping
            // would be wrong once entries merge: a refused edit folded into
            // the previous gesture would take that gesture's undo step with
            // it, even though the document still holds it.
            const historyBefore = [...this.undoStack]

            this.pushHistory({
                label,
                undo: inverse,
                redo: operations,
                key: coalesceKey,
                at: Date.now(),
            })

            if ((await this.send(api, operations)) === 'refused') {
                // The server answered no — retrying a refusal just refuses.
                this.blocks = applyPatch(this.blocks, inverse).document
                this.undoStack = historyBefore

                return false
            }

            return true
        },

        /**
         * Put one batch on the wire, the same way for every caller.
         *
         * Extracted because undo did not do this and it mattered: it patched
         * the server directly, so an undo pressed while a send queue existed
         * jumped the queue — and order is the whole guarantee a queue makes.
         * Offline it was worse than out of order, it was refused outright and
         * rolled back, so undo was the one gesture that stopped working when
         * the connection did.
         *
         * Three outcomes, and the caller decides what each means to it:
         * `ok` (the server has it), `queued` (it will, keep the local state),
         * `refused` (it never will, put the document back).
         */
        async send(api: BuilderApi, operations: PatchOperation[]): Promise<SendOutcome> {
            // While a queue exists, everything joins it instead of racing it.
            if (this.sendQueue.length > 0) {
                this.enqueue(operations)

                return 'queued'
            }

            this.saving = true
            try {
                const result = await api.patch(operations)
                // Adopt the server's document: it is the one that exists.
                this.blocks = result.document

                return 'ok'
            } catch (error) {
                if (classifyFailure(error) === 'network') {
                    // The server never answered: the edit is not wrong, the
                    // connection is. Keep it, queue it, replay later.
                    this.enqueue(operations)

                    return 'queued'
                }

                this.error = error instanceof Error ? error.message : String(error)

                return 'refused'
            } finally {
                this.saving = false
            }
        },

        enqueue(operations: PatchOperation[]): void {
            this.sendQueue.push({ operations, queuedAt: Date.now() })
            void persistQueue(this.pageId, [...this.sendQueue])
        },

        /**
         * Replay queued batches strictly in order. A network failure stops
         * and keeps the rest; a refusal abandons the queue and reloads —
         * the document moved underneath (takeover, permissions) and stale
         * operations must not be forced over it.
         */
        async replayQueue(api: BuilderApi): Promise<void> {
            if (this.replaying || this.sendQueue.length === 0) {
                return
            }

            this.replaying = true
            try {
                while (this.sendQueue.length > 0) {
                    const batch = this.sendQueue[0]
                    try {
                        const result = await api.patch(batch.operations)
                        this.blocks = result.document
                        this.sendQueue.shift()
                        void persistQueue(this.pageId, [...this.sendQueue])
                    } catch (error) {
                        if (classifyFailure(error) === 'network') {
                            return // still offline; keep the queue intact
                        }

                        this.sendQueue = []
                        void persistQueue(this.pageId, [])
                        this.error =
                            'Offline changes could not be applied — the page changed while you were away. ' +
                            (error instanceof Error ? error.message : '')
                        await this.load(api)

                        return
                    }
                }
            } finally {
                this.replaying = false
            }
        },

        /** Crash recovery: batches persisted by a previous session. */
        async restoreQueue(api: BuilderApi): Promise<void> {
            const persisted = await loadQueue(this.pageId)
            if (persisted.length === 0) {
                return
            }

            this.sendQueue = persisted
            await this.replayQueue(api)
            // Whatever replay decided, the store now mirrors the server.
            if (this.sendQueue.length === 0) {
                await this.load(api)
            }
        },

        async undo(api: BuilderApi): Promise<void> {
            const entry = this.undoStack.pop()
            if (!entry) {
                return
            }

            this.error = null

            const applied = applyPatch(this.blocks, entry.undo)
            this.blocks = applied.document
            this.redoStack.push(entry)

            // Through the same send path as every other edit, so an undo
            // pressed offline is kept and replayed rather than refused, and
            // one pressed while a queue exists lands after what is in it.
            if ((await this.send(api, entry.undo)) === 'refused') {
                this.blocks = applyPatch(this.blocks, applied.inverse).document
                this.undoStack.push(entry)
                /*
                 * Removed by identity, not by popping: the await above is a
                 * gap somebody can press redo in, and popping would take
                 * whatever happened to be on top instead of the entry this
                 * call put there.
                 */
                this.redoStack = this.redoStack.filter((candidate) => candidate !== entry)
            }
        },

        async redo(api: BuilderApi): Promise<void> {
            const entry = this.redoStack.pop()
            if (!entry) {
                return
            }

            await this.edit(api, entry.label, entry.redo)
        },

        /**
         * Structure gestures. Each returns whether the edit survived the
         * server, so the caller knows whether to refresh the canvas — and
         * each is a no-op when the gesture does not apply to the current
         * document, rather than sending a patch that would be refused.
         */
        async addBlock(
            api: BuilderApi,
            parentId: string,
            handle: string,
            index?: number,
        ): Promise<boolean> {
            const definition = this.registry.find((entry) => entry.handle === handle)
            if (!definition) {
                return false
            }

            const block = blockFrom(definition)
            // A click appends; a drop states exactly where the line was drawn.
            const operations = insertBlock(this.blocks, parentId, block, index)
            if (!operations) {
                return false
            }

            const ok = await this.edit(api, `Add ${definition.label}`, operations)
            if (ok) {
                this.select(block.id)
            }

            return ok
        },

        /**
         * Add a section with a chosen column structure. `index` places it
         * (a drop between sections); omitted, it appends. Selecting the
         * new section afterwards is what makes "add, then style it" one
         * gesture instead of two.
         */
        async addSection(api: BuilderApi, spans?: number[], index?: number): Promise<boolean> {
            const section = emptySection(spans)
            const operations =
                index === undefined
                    ? appendSection(this.blocks, section)
                    : insertSectionsAt(this.blocks, [section], index)

            const ok = await this.edit(api, 'Add section', operations)
            if (ok) {
                this.select(section.id)
            }

            return ok
        },

        async moveSection(api: BuilderApi, sectionId: string, index: number): Promise<boolean> {
            const operations = moveSection(this.blocks, sectionId, index)
            if (!operations || operations.length === 0) {
                return operations !== null
            }

            return this.edit(api, 'Move section', operations)
        },

        async addColumn(api: BuilderApi, sectionId: string): Promise<boolean> {
            const operations = addColumn(this.blocks, sectionId)
            if (!operations) {
                this.error = 'This section already has the most columns a row can hold.'

                return false
            }

            return this.edit(api, 'Add column', operations)
        },

        async removeColumn(api: BuilderApi, sectionId: string, columnId: string): Promise<boolean> {
            const operations = removeColumn(this.blocks, sectionId, columnId)
            if (!operations) {
                this.error = 'A section keeps at least one column.'

                return false
            }

            const ok = await this.edit(api, 'Remove column', operations)
            if (ok && this.selectedNode === columnId) {
                this.select(sectionId)
            }

            return ok
        },

        async setSpans(api: BuilderApi, sectionId: string, spans: number[]): Promise<boolean> {
            const operations = setSpans(this.blocks, sectionId, spans)
            if (!operations) {
                return false
            }

            return this.edit(api, 'Resize columns', operations)
        },

        /** Duplicate any node in place — section, column or block. */
        async duplicateNode(api: BuilderApi, nodeId: string): Promise<boolean> {
            const operations = duplicateNode(this.blocks, nodeId)
            if (!operations) {
                return false
            }

            return this.edit(api, 'Duplicate', operations)
        },

        async removeNode(api: BuilderApi, nodeId: string): Promise<boolean> {
            const operations = removeNode(this.blocks, nodeId)
            if (!operations) {
                return false
            }

            const ok = await this.edit(api, 'Delete', operations)
            if (ok && this.selectedNode === nodeId) {
                this.select(null)
            }

            return ok
        },

        async moveBlock(
            api: BuilderApi,
            nodeId: string,
            parentId: string,
            index: number,
        ): Promise<boolean> {
            const operations = moveNode(this.blocks, nodeId, parentId, index)
            if (!operations || operations.length === 0) {
                return operations !== null
            }

            return this.edit(api, 'Move block', operations)
        },

        select(nodeId: string | null): void {
            this.selectedNode = nodeId
        },

        /**
         * Copy the selection to the builder clipboard.
         *
         * Kept in localStorage rather than the system clipboard: a
         * document node is JSON, not text, and asking for clipboard
         * permission to move a heading between two tabs of the same app
         * is a worse trade than a key nobody else reads. It also makes
         * paste work between pages, which is the case people actually
         * want.
         */
        copyNode(nodeId?: string | null): boolean {
            const target = nodeId === undefined ? this.selectedNode : nodeId
            if (target === null) {
                return false
            }

            const found = locate(this.blocks, target)
            if (!found) {
                return false
            }

            this.clipboard = { kind: found.kind, node: found.node as Record<string, unknown> }
            try {
                window.localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(this.clipboard))
            } catch {
                // In-memory copy still works for this tab.
            }

            return true
        },

        /** Load a clipboard written by another page of the same site. */
        restoreClipboard(): void {
            if (this.clipboard !== null) {
                return
            }

            try {
                const raw = window.localStorage.getItem(CLIPBOARD_KEY)
                if (raw === null) {
                    return
                }
                const parsed = JSON.parse(raw) as { kind?: string; node?: unknown }
                if (
                    (parsed.kind === 'block' || parsed.kind === 'section' || parsed.kind === 'column') &&
                    parsed.node !== null &&
                    typeof parsed.node === 'object'
                ) {
                    this.clipboard = { kind: parsed.kind, node: parsed.node as Record<string, unknown> }
                }
            } catch {
                // A corrupt clipboard is simply no clipboard.
            }
        },

        /**
         * Paste the clipboard next to the selection. Fresh ids throughout:
         * pasting a node that kept its id would make every pointer to it
         * ambiguous.
         */
        /**
         * Name a node. Editorial metadata: it never reaches the page, so
         * it is a content edit rather than a design one, and clearing it
         * removes the key rather than storing an empty string.
         */
        async renameNode(api: BuilderApi, nodeId: string, label: string): Promise<boolean> {
            const found = locate(this.blocks, nodeId)
            if (!found) {
                return false
            }

            const settings = (found.node as { settings?: Record<string, unknown> }).settings ?? {}
            const trimmed = label.trim()

            if (trimmed === '') {
                return 'label' in settings
                    ? this.edit(api, 'Rename', [{ op: 'remove', path: `${found.pointer}/settings/label` }])
                    : false
            }

            return this.edit(api, 'Rename', [
                { op: 'add', path: `${found.pointer}/settings/label`, value: trimmed },
            ])
        },

        /** Copy a node's style set, so another node can wear the same one. */
        copyStyles(nodeId: string): boolean {
            const found = locate(this.blocks, nodeId)
            const style = (found?.node as { settings?: { style?: unknown } } | undefined)?.settings?.style

            if (typeof style !== 'object' || style === null) {
                this.error = 'That node has no styles to copy.'

                return false
            }

            this.styleClipboard = { ...(style as Record<string, unknown>) }

            return true
        },

        /**
         * Apply the copied style set, replacing whatever the target had.
         * Replacing rather than merging: "paste styles" means the target
         * ends up looking like the source, and a merge would leave the
         * target's leftovers showing through.
         */
        async pasteStyles(api: BuilderApi, nodeId: string): Promise<boolean> {
            const found = locate(this.blocks, nodeId)
            if (!found || this.styleClipboard === null) {
                return false
            }

            return this.edit(api, 'Paste styles', [
                { op: 'add', path: `${found.pointer}/settings/style`, value: { ...this.styleClipboard } },
            ])
        },

        async pasteNode(api: BuilderApi): Promise<boolean> {
            this.restoreClipboard()
            const entry = this.clipboard
            if (entry === null) {
                return false
            }

            const node = withFreshIds(entry.node)

            if (entry.kind === 'block') {
                const parent = this.insertionParent
                if (parent === null) {
                    this.error = 'Choose a column to paste into.'

                    return false
                }

                const operations = insertBlock(this.blocks, parent, node as never)

                return operations ? this.edit(api, 'Paste', operations) : false
            }

            if (entry.kind === 'section') {
                return this.edit(api, 'Paste', appendSection(this.blocks, node as never))
            }

            // A column pastes as a new column on the selected section — a
            // column has no meaning outside one. The row rebalances: the
            // pasted column keeps its content, not its old width, because a
            // row that does not sum to twelve lays out wrong.
            const section = this.selectedNode ? locate(this.blocks, this.selectedNode) : null
            if (!section || section.kind !== 'section') {
                this.error = 'Select a section to paste this column into.'

                return false
            }

            const spans = ((section.node as { columns?: { span: number }[] }).columns ?? []).map(
                (column) => column.span,
            )

            return this.edit(
                api,
                'Paste column',
                pasteColumnOperations(section.pointer, spans, node as Record<string, unknown>),
            )
        },

        /**
         * Record an edit as an undo step, folding it into the previous one
         * when both belong to the same continuous gesture.
         *
         * Merging keeps the OLDER entry's undo and appends to its redo, so
         * the merged step still reverts to where the gesture started and
         * still replays the whole of it. Without this, one drag of a colour
         * picker buries every earlier edit under forty identical steps.
         */
        pushHistory(entry: HistoryEntry): void {
            const previous = this.undoStack[this.undoStack.length - 1]
            const sameGesture =
                previous !== undefined &&
                entry.key !== undefined &&
                previous.key === entry.key &&
                entry.at - previous.at < COALESCE_WINDOW_MS

            if (sameGesture) {
                this.undoStack[this.undoStack.length - 1] = {
                    label: previous.label,
                    key: previous.key,
                    at: entry.at,
                    // Undo runs newest-first, so the new inverse leads.
                    undo: [...entry.undo, ...previous.undo],
                    redo: [...previous.redo, ...entry.redo],
                }
                this.redoStack = []

                return
            }

            this.undoStack.push(entry)
            if (this.undoStack.length > HISTORY_LIMIT) {
                this.undoStack.shift()
            }
            // A new edit forks the timeline; anything redone from here on
            // would reapply operations against a document that no longer
            // matches what they were computed from.
            this.redoStack = []
        },
    },
})
