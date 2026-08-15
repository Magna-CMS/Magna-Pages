import { describe, expect, it } from 'vitest'

import { nodeActions, type NodeActionContext } from './actions'

/**
 * The canvas menu and the navigator draw from this one table, so a rule
 * asserted here is a rule both surfaces obey — which is the whole reason
 * it is a table and not two lists of buttons.
 */
function context(overrides: Partial<NodeActionContext> = {}): NodeActionContext {
    return {
        kind: 'block',
        canStructure: true,
        canContent: true,
        canStyle: true,
        holdsLock: true,
        hasClipboard: false,
        hasStyles: false,
        isFirst: false,
        isLast: false,
        // A block with nowhere to nest is the ordinary case; the specs
        // that care about nesting opt in.
        canMoveInto: false,
        canMoveOut: false,
        ...overrides,
    }
}

function enabled(overrides: Partial<NodeActionContext> = {}): string[] {
    return nodeActions(context(overrides))
        .filter((action) => action.enabled)
        .map((action) => action.key)
}

describe('nodeActions', () => {
    it('offers the full set to an editor with the lock and the permissions', () => {
        expect(enabled({ hasClipboard: true, hasStyles: true, kind: 'section' })).toEqual([
            'moveUp',
            'moveDown',
            'duplicate',
            'rename',
            'copy',
            'paste',
            'copyStyles',
            'pasteStyles',
            'savePattern',
            'delete',
        ])
    })

    it('disables every structural action without the lock', () => {
        // Nothing would save, so offering it is offering a failure.
        // Copying is a read; naming and pasting are writes that would not save.
        expect(enabled({ holdsLock: false, hasClipboard: true, hasStyles: true })).toEqual([
            'copy',
            'savePattern',
        ])
    })

    it('disables structural actions without the layout permission', () => {
        expect(enabled({ canStructure: false, hasClipboard: true })).toEqual([
            'rename',
            'copy',
            'savePattern',
        ])
    })

    it('says why, rather than hiding the option', () => {
        const actions = nodeActions(context({ canStructure: false }))

        expect(actions).toHaveLength(12)
        expect(actions.find((action) => action.key === 'delete')?.hint).toBe(
            'Needs the layout permission',
        )
    })

    it('offers nesting only where there is somewhere to nest', () => {
        // Dragging is not everyone's input device, so the two directions an
        // outline editor has always had are on the table too.
        expect(enabled({ canMoveInto: true })).toContain('moveInto')
        expect(enabled({ canMoveOut: true })).toContain('moveOut')
        expect(enabled()).not.toContain('moveInto')
        expect(enabled()).not.toContain('moveOut')
    })

    it('says what is missing when a block has nowhere to nest', () => {
        const actions = nodeActions(context())

        expect(actions.find((action) => action.key === 'moveInto')?.hint).toBe(
            'Put a container directly above it first',
        )
        expect(actions.find((action) => action.key === 'moveOut')?.hint).toBe(
            'It is not inside a container',
        )
    })

    it('never offers nesting to a section or a column', () => {
        const section = enabled({ kind: 'section', canMoveInto: true, canMoveOut: true })

        expect(section).not.toContain('moveInto')
        expect(section).not.toContain('moveOut')
    })

    it('will not move the first node up or the last node down', () => {
        expect(enabled({ isFirst: true })).not.toContain('moveUp')
        expect(enabled({ isFirst: true })).toContain('moveDown')
        expect(enabled({ isLast: true })).not.toContain('moveDown')
    })

    it('treats a column as shaped by its row, not ordered among siblings', () => {
        const actions = enabled({ kind: 'column', hasClipboard: true })

        expect(actions).not.toContain('moveUp')
        expect(actions).not.toContain('duplicate')
        expect(actions).not.toContain('savePattern')
        // Pasting INTO a column and deleting one are still meaningful.
        expect(actions).toContain('paste')
        expect(actions).toContain('delete')
    })

    it('refuses paste with an empty clipboard, and says so', () => {
        const paste = nodeActions(context({ hasClipboard: false })).find((a) => a.key === 'paste')

        expect(paste?.enabled).toBe(false)
        expect(paste?.hint).toBe('Nothing copied yet')
    })

    it('lets a copy happen even when nothing may be written', () => {
        // Copying reads the document; it is not an edit, and a read-only
        // visitor taking a section to another page is a real workflow.
        expect(
            enabled({ holdsLock: false, canStructure: false, canContent: false, canStyle: false }),
        ).toEqual(['copy'])
    })
})
