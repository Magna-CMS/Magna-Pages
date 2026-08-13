import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { clampWidth, useUiStore } from './ui'

/**
 * Chrome state, not document state. These tests exist because the panel's
 * remembered width and category collapse are the two things an editor
 * notices immediately when they regress, and neither is covered by any
 * document test.
 */

/** A localStorage stand-in — the suite runs in node, which has none. */
function stubStorage(seed: Record<string, string> = {}) {
    const store = new Map(Object.entries(seed))

    ;(globalThis as unknown as { window: unknown }).window = {
        localStorage: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
        },
    }

    return store
}

beforeEach(() => {
    stubStorage()
    setActivePinia(createPinia())
})

afterEach(() => {
    delete (globalThis as unknown as { window?: unknown }).window
})

describe('clampWidth', () => {
    it('keeps the panel between its bounds', () => {
        expect(clampWidth(10)).toBe(260)
        expect(clampWidth(9999)).toBe(480)
        expect(clampWidth(321)).toBe(321)
    })

    it('falls back rather than producing NaN chrome', () => {
        expect(clampWidth(Number.NaN)).toBe(300)
    })
})

describe('categories', () => {
    it('opens a category nobody has closed', () => {
        // A newly installed plugin's category must arrive expanded: a block
        // nobody can find is a block nobody uses.
        expect(useUiStore().categoryOpen('anything')).toBe(true)
    })

    it('toggles closed and open again', () => {
        const ui = useUiStore()

        ui.toggleCategory('media')
        expect(ui.categoryOpen('media')).toBe(false)

        ui.toggleCategory('media')
        expect(ui.categoryOpen('media')).toBe(true)
    })
})

describe('modes', () => {
    it('inspecting opens a collapsed panel', () => {
        const ui = useUiStore()
        ui.panelCollapsed = true

        ui.inspect('style')

        expect(ui.mode).toBe('inspect')
        expect(ui.inspectTab).toBe('style')
        expect(ui.panelCollapsed).toBe(false)
    })

    it('browsing clears the last search', () => {
        const ui = useUiStore()
        ui.search = 'hero'

        ui.browse('cloud')

        expect(ui.mode).toBe('library')
        expect(ui.search).toBe('')
        expect(ui.libraryTab).toBe('cloud')
    })

    it('re-pressing a dock tab closes its drawer', () => {
        const ui = useUiStore()

        ui.toggleDrawer('layers')
        expect(ui.drawer).toBe('layers')

        ui.toggleDrawer('layers')
        expect(ui.drawer).toBeNull()
    })
})

describe('persistence', () => {
    it('stores layout preferences and nothing else', () => {
        const storage = stubStorage()
        setActivePinia(createPinia())

        const ui = useUiStore()
        ui.setPanelWidth(400)
        ui.toggleCategory('media')
        ui.search = 'not persisted'
        ui.drawer = 'checks'

        const raw = JSON.parse(storage.get('magna-builder-ui') ?? '{}') as Record<string, unknown>

        expect(raw).toEqual({ panelWidth: 400, panelCollapsed: false, collapsedCategories: ['media'] })
    })

    it('restores what the last session left', () => {
        stubStorage({
            'magna-builder-ui': JSON.stringify({
                panelWidth: 999,
                panelCollapsed: true,
                collapsedCategories: ['layout'],
            }),
        })
        setActivePinia(createPinia())

        const ui = useUiStore()

        expect(ui.panelWidth).toBe(480)
        expect(ui.panelCollapsed).toBe(true)
        expect(ui.categoryOpen('layout')).toBe(false)
    })

    it('survives a corrupt store rather than refusing to open', () => {
        stubStorage({ 'magna-builder-ui': '{not json' })
        setActivePinia(createPinia())

        expect(useUiStore().panelWidth).toBe(300)
    })
})
