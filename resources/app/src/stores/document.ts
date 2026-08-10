import { defineStore } from 'pinia'

import type { BuilderApi } from '../api'
import {
    appendSection,
    blockFrom,
    emptySection,
    insertBlock,
    moveNode,
    removeNode,
} from '../document/edits'
import { locate } from '../document/locate'
import { applyPatch } from '../document/patch'
import { classifyFailure, loadQueue, persistQueue, type QueuedBatch } from '../resilience'
import type {
    ApprovalState,
    BlockDefinition,
    BlockDocument,
    Capabilities,
    LockState,
    PatchOperation,
    SectionNode,
} from '../document/types'
import { sectionsOf } from '../document/types'

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

interface HistoryEntry {
    label: string
    undo: PatchOperation[]
    redo: PatchOperation[]
}

export interface PatternSummary {
    id: string
    name: string
    kind: string
}

export interface LibraryAssetSummary {
    slug: string
    name: string
    kind: string
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
    tokens: Record<string, string>
    capabilities: Capabilities
    selectedNode: string | null
    lock: LockState
    approval: ApprovalState | null
    bindingSources: Record<string, string>
    undoStack: HistoryEntry[]
    redoStack: HistoryEntry[]
    saving: boolean
    error: string | null
    loaded: boolean
    /** Batches accepted locally but not yet acknowledged by the server. */
    sendQueue: QueuedBatch[]
    replaying: boolean
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
        tokens: {},
        capabilities: { content: false, structure: false, style: false, publish: false },
        selectedNode: null,
        lock: { mine: false, holder: null },
        approval: null,
        bindingSources: {},
        undoStack: [],
        redoStack: [],
        saving: false,
        error: null,
        loaded: false,
        sendQueue: [],
        replaying: false,
    }),

    getters: {
        sections: (state): SectionNode[] => sectionsOf(state.blocks),
        canUndo: (state): boolean => state.undoStack.length > 0,
        canRedo: (state): boolean => state.redoStack.length > 0,
        blockDefinition: (state) => {
            return (handle: string): BlockDefinition | undefined =>
                state.registry.find((definition) => definition.handle === handle)
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
            this.tokens = payload.tokens
            this.capabilities = payload.capabilities
            this.lock = payload.lock ?? { mine: true, holder: null }
            this.approval = payload.approval ?? null
            this.bindingSources = payload.bindingSources ?? {}
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
         * Insert a cloud-library asset. Patterns and parts are one section;
         * a page asset is a whole sections list, appended in order.
         */
        async insertLibraryAsset(api: BuilderApi, slug: string): Promise<boolean> {
            this.error = null

            let instance: { kind: string; node: Record<string, unknown> }
            try {
                instance = await api.libraryInstance(slug)
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            const nodes =
                instance.kind === 'page' && Array.isArray(instance.node)
                    ? (instance.node as unknown as Record<string, unknown>[])
                    : [instance.node]

            const operations = nodes.flatMap((node) => appendSection(this.blocks, node as never))

            return this.edit(api, 'Insert from library', operations)
        },

        /** Insert a pattern instance: sections append, blocks join a column. */
        async insertPattern(
            api: BuilderApi,
            patternId: string,
            targetColumn: string | null,
        ): Promise<boolean> {
            this.error = null

            let instance: { kind: string; node: Record<string, unknown> }
            try {
                instance = await api.patternInstance(patternId)
            } catch (error) {
                this.error = error instanceof Error ? error.message : String(error)

                return false
            }

            const operations =
                instance.kind === 'section'
                    ? appendSection(this.blocks, instance.node as never)
                    : targetColumn !== null
                      ? insertBlock(this.blocks, targetColumn, instance.node as never)
                      : null

            if (!operations) {
                this.error = 'Select a column to place this block pattern in.'

                return false
            }

            return this.edit(api, 'Insert pattern', operations)
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
         */
        async edit(api: BuilderApi, label: string, operations: PatchOperation[]): Promise<boolean> {
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

            this.pushHistory({ label, undo: inverse, redo: operations })

            // While a queue exists, new edits join it instead of racing it —
            // order is the whole guarantee of a send queue.
            if (this.sendQueue.length > 0) {
                this.enqueue(operations)

                return true
            }

            this.saving = true
            try {
                const result = await api.patch(operations)
                // Adopt the server's document: it is the one that exists.
                this.blocks = result.document

                return true
            } catch (error) {
                if (classifyFailure(error) === 'network') {
                    // The server never answered: the edit is not wrong, the
                    // connection is. Keep it, queue it, replay later.
                    this.enqueue(operations)

                    return true
                }

                // The server answered no — retrying a refusal just refuses.
                this.blocks = applyPatch(this.blocks, inverse).document
                this.undoStack.pop()
                this.error = error instanceof Error ? error.message : String(error)

                return false
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

            const applied = applyPatch(this.blocks, entry.undo)
            this.blocks = applied.document
            this.redoStack.push(entry)

            try {
                const result = await api.patch(entry.undo)
                this.blocks = result.document
            } catch (error) {
                this.blocks = applyPatch(this.blocks, applied.inverse).document
                this.undoStack.push(entry)
                this.redoStack.pop()
                this.error = error instanceof Error ? error.message : String(error)
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
        async addBlock(api: BuilderApi, columnId: string, handle: string): Promise<boolean> {
            const definition = this.registry.find((entry) => entry.handle === handle)
            if (!definition) {
                return false
            }

            const block = blockFrom(definition)
            const operations = insertBlock(this.blocks, columnId, block)
            if (!operations) {
                return false
            }

            const ok = await this.edit(api, `Add ${definition.label}`, operations)
            if (ok) {
                this.select(block.id)
            }

            return ok
        },

        async addSection(api: BuilderApi): Promise<boolean> {
            const section = emptySection()

            return this.edit(api, 'Add section', appendSection(this.blocks, section))
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
            columnId: string,
            index: number,
        ): Promise<boolean> {
            const operations = moveNode(this.blocks, nodeId, columnId, index)
            if (!operations || operations.length === 0) {
                return operations !== null
            }

            return this.edit(api, 'Move block', operations)
        },

        select(nodeId: string | null): void {
            this.selectedNode = nodeId
        },

        pushHistory(entry: HistoryEntry): void {
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
