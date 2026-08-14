import { describe, expect, it, vi } from 'vitest'

import { buildActions, filterActions, type PaletteContext } from './palette'

function context(overrides: Partial<PaletteContext> = {}): PaletteContext {
    return {
        capabilities: { content: true, structure: true, style: true, publish: true },
        lockMine: true,
        hasSelection: true,
        targetParent: 'col-1',
        blocks: [
            { handle: 'heading', label: 'Heading', requiresPermission: null },
            { handle: 'html', label: 'HTML', requiresPermission: 'blocks.raw_html' },
        ],
        patterns: [
            { id: 'p1', name: 'Hero band', kind: 'section' },
            { id: 'p2', name: 'Card', kind: 'block' },
        ],
        breakpoints: ['desktop', 'tablet', 'mobile'],
        handlers: {
            addBlock: vi.fn(),
            addSection: vi.fn(),
            insertPattern: vi.fn(),
            setBreakpoint: vi.fn(),
            undo: vi.fn(),
            redo: vi.fn(),
            deleteSelection: vi.fn(),
            publish: vi.fn(),
            savePattern: vi.fn(),
        },
        ...overrides,
    }
}

describe('buildActions', () => {
    it('offers everything to a fully-capable lock holder', () => {
        const ids = buildActions(context()).map((action) => action.id)

        expect(ids).toContain('add-section')
        expect(ids).toContain('add-block-heading')
        expect(ids).toContain('pattern-p1')
        expect(ids).toContain('publish')
        expect(ids).toContain('delete-selection')
    })

    it('never offers what the actor cannot do', () => {
        const ids = buildActions(
            context({
                capabilities: { content: true, structure: false, style: false, publish: false },
            }),
        ).map((action) => action.id)

        expect(ids).not.toContain('add-section')
        expect(ids).not.toContain('add-block-heading')
        expect(ids).not.toContain('publish')
        // Edit and view survive: undo and breakpoints are always safe.
        expect(ids).toContain('undo')
        expect(ids).toContain('breakpoint-mobile')
    })

    it('omits permission-gated blocks the actor cannot insert', () => {
        const ids = buildActions(
            context({
                capabilities: { content: true, structure: true, style: false, publish: false },
            }),
        ).map((action) => action.id)

        expect(ids).toContain('add-block-heading')
        expect(ids).not.toContain('add-block-html')
    })

    it('hides publish when the lock is not held', () => {
        const ids = buildActions(context({ lockMine: false })).map((action) => action.id)

        expect(ids).not.toContain('publish')
    })

    it('drops column-dependent inserts when no column is targeted', () => {
        const ids = buildActions(context({ targetParent: null })).map((action) => action.id)

        expect(ids).not.toContain('add-block-heading')
        expect(ids).not.toContain('pattern-p2') // block pattern needs a column
        expect(ids).toContain('pattern-p1') // section pattern does not
    })
})

describe('filterActions', () => {
    const actions = buildActions(context())

    it('matches subsequences case-insensitively', () => {
        const labels = filterActions(actions, 'ins hea').map((action) => action.label)

        expect(labels[0]).toBe('Insert Heading')
    })

    it('matches keywords the label does not show', () => {
        const labels = filterActions(actions, 'viewport').map((action) => action.label)

        expect(labels).toContain('Preview: mobile')
    })

    it('returns everything for an empty query and nothing for garbage', () => {
        expect(filterActions(actions, '')).toHaveLength(actions.length)
        expect(filterActions(actions, 'zzzqqqxxx')).toHaveLength(0)
    })

    it('ranks tighter earlier matches first', () => {
        const labels = filterActions(actions, 'undo').map((action) => action.label)

        expect(labels[0]).toBe('Undo')
    })
})
