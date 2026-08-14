import { describe, expect, it } from 'vitest'

import { SHORTCUTS, shortcutGroups } from './shortcuts'

/**
 * A help panel that lists a shortcut nobody implemented is worse than no
 * help panel: it teaches a gesture, the gesture does nothing, and the
 * editor concludes the whole list is decorative.
 */
describe('SHORTCUTS', () => {
    it('describes every entry', () => {
        for (const shortcut of SHORTCUTS) {
            expect(shortcut.keys.trim()).not.toBe('')
            expect(shortcut.description.trim()).not.toBe('')
            expect(shortcut.group.trim()).not.toBe('')
        }
    })

    it('does not list the same keys twice in one group', () => {
        // Esc means two different things in two different contexts, which
        // is fine — the same thing twice in ONE context is a mistake.
        for (const [, entries] of shortcutGroups()) {
            const keys = entries.map((entry) => entry.keys)

            expect(new Set(keys).size).toBe(keys.length)
        }
    })

    it('groups in the order the groups first appear', () => {
        expect(shortcutGroups().map(([group]) => group)).toEqual(['Everywhere', 'Editing', 'Canvas'])
    })
})
