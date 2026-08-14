import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError, type BuilderApi } from '../api'
import { sectionsOf, type BlockDocument, type PatchOperation, type SectionNode } from '../document/types'
import { useDocumentStore } from './document'

/**
 * History, and what one gesture is.
 *
 * Undo is only useful if a step is a thing the editor did. A colour picker
 * dragged across a gradient fires an edit per pointer move, and without
 * coalescing forty of those bury every earlier step — the editor presses
 * undo, watches the same swatch inch backwards, and gives up
 * (12-BUILDER-REDESIGN §17).
 */

function documentWith(text: string): SectionNode[] {
    return [
        {
            id: 'sec-1',
            type: 'section',
            settings: {},
            columns: [
                {
                    id: 'col-1',
                    span: 12,
                    settings: {},
                    blocks: [{ id: 'blk-1', block: 'heading', settings: {}, data: { text } }],
                },
            ],
        },
    ]
}

/** An API that accepts everything and echoes the document it was given. */
function acceptingApi(store: { blocks: BlockDocument }): BuilderApi {
    return {
        patch: vi.fn(async () => ({ document: store.blocks })),
    } as unknown as BuilderApi
}

/**
 * An API that refuses every write, the way an unauthorized patch does.
 *
 * ApiError specifically: that is what tells the store the server ANSWERED
 * and said no. A plain Error reads as "no answer", which queues the edit
 * instead of rolling it back — a different path entirely.
 */
function refusingApi(): BuilderApi {
    return {
        patch: vi.fn(async () => {
            throw new ApiError('This edit needs the "pages.layout" permission.', 422)
        }),
    } as unknown as BuilderApi
}

const setText = (value: string): PatchOperation[] => [
    { op: 'replace', path: '/0/columns/0/blocks/0/data/text', value },
]

/** The one block's text, whichever document shape the store holds. */
function textIn(document: BlockDocument): unknown {
    return sectionsOf(document)[0].columns?.[0].blocks?.[0].data?.text
}

function freshStore() {
    const store = useDocumentStore()
    store.blocks = documentWith('One')

    return store
}

beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('coalescing history', () => {
    it('folds successive edits of one field into a single undo step', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        for (const value of ['Tw', 'Two', 'Two!']) {
            await store.edit(api, 'Edit text', setText(value), 'field:blk-1:text')
            vi.advanceTimersByTime(50)
        }

        expect(store.undoStack).toHaveLength(1)
    })

    it('undoes the whole gesture, back to where it started', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        for (const value of ['Tw', 'Two', 'Two!']) {
            await store.edit(api, 'Edit text', setText(value), 'field:blk-1:text')
            vi.advanceTimersByTime(50)
        }

        await store.undo(api)

        expect(textIn(store.blocks)).toBe('One')
    })

    it('redoes the whole gesture in one step', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        for (const value of ['Tw', 'Two', 'Two!']) {
            await store.edit(api, 'Edit text', setText(value), 'field:blk-1:text')
            vi.advanceTimersByTime(50)
        }

        await store.undo(api)
        await store.redo(api)

        expect(textIn(store.blocks)).toBe('Two!')
        expect(store.undoStack).toHaveLength(1)
    })

    it('starts a new step once the editor pauses', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        await store.edit(api, 'Edit text', setText('Two'), 'field:blk-1:text')
        // Coming back to the same field a moment later is a separate thing
        // to undo, not a continuation of the first.
        vi.advanceTimersByTime(2_000)
        await store.edit(api, 'Edit text', setText('Three'), 'field:blk-1:text')

        expect(store.undoStack).toHaveLength(2)
    })

    it('keeps different fields apart even when they are edited together', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        await store.edit(api, 'Edit text', setText('Two'), 'field:blk-1:text')
        await store.edit(
            api,
            'Style paddingTop',
            [{ op: 'add', path: '/0/settings/style', value: { paddingTop: '4px' } }],
            'style:/0:style:paddingTop:base',
        )

        expect(store.undoStack).toHaveLength(2)
    })

    it('never folds an edit that names no gesture', async () => {
        const store = freshStore()
        const api = acceptingApi(store)

        // Structural edits are discrete by nature; two deletes in a second
        // are two things to undo.
        await store.edit(api, 'Edit text', setText('Two'))
        await store.edit(api, 'Edit text', setText('Three'))

        expect(store.undoStack).toHaveLength(2)
    })
})

describe('a refused edit', () => {
    it('leaves the earlier gesture it would have merged into intact', async () => {
        const store = freshStore()

        await store.edit(acceptingApi(store), 'Edit text', setText('Two'), 'field:blk-1:text')
        vi.advanceTimersByTime(50)

        const accepted = store.blocks
        await store.edit(refusingApi(), 'Edit text', setText('Three'), 'field:blk-1:text')

        // The refusal rolls its own change back and no further: popping the
        // merged entry would have thrown away the accepted edit's undo step
        // while the document still held it.
        expect(store.blocks).toEqual(accepted)
        expect(store.undoStack).toHaveLength(1)
        expect(store.error).toContain('pages.layout')
    })

    it('undoes back to the start after a refusal folded into the gesture', async () => {
        const store = freshStore()

        await store.edit(acceptingApi(store), 'Edit text', setText('Two'), 'field:blk-1:text')
        vi.advanceTimersByTime(50)
        await store.edit(refusingApi(), 'Edit text', setText('Three'), 'field:blk-1:text')
        await store.undo(acceptingApi(store))

        expect(textIn(store.blocks)).toBe('One')
    })

    it('drops the whole entry when the refused edit was the only one in it', async () => {
        const store = freshStore()
        const before = store.blocks

        await store.edit(refusingApi(), 'Edit text', setText('Two'), 'field:blk-1:text')

        expect(store.blocks).toEqual(before)
        expect(store.undoStack).toHaveLength(0)
    })
})
