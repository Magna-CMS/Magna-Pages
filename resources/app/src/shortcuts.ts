/**
 * The keyboard shortcuts the builder answers to, as data.
 *
 * One list, read by the help panel and — where the binding is simple
 * enough to describe — by nothing else: the handlers still live where the
 * behaviour is. That split is deliberate. A table that CLAIMED to bind the
 * keys would have to model modifiers, focus context and the typing guard,
 * and the day it drifted from the real handler the panel would confidently
 * teach the wrong shortcut. This list documents; App.vue decides.
 *
 * Which means the one thing worth testing is that it stays honest: every
 * entry names a key the editor really handles.
 */

export interface Shortcut {
    keys: string
    description: string
    group: string
}

export const SHORTCUTS: Shortcut[] = [
    { keys: 'Ctrl K', description: 'Open the command palette', group: 'Everywhere' },
    { keys: '?', description: 'Show this list', group: 'Everywhere' },
    { keys: 'Esc', description: 'Close what is open', group: 'Everywhere' },

    { keys: 'Ctrl Z', description: 'Undo', group: 'Editing' },
    { keys: 'Ctrl Shift Z', description: 'Redo', group: 'Editing' },
    { keys: 'Ctrl C', description: 'Copy the selection', group: 'Editing' },
    { keys: 'Ctrl V', description: 'Paste into the selection', group: 'Editing' },
    { keys: 'Delete', description: 'Delete the selection', group: 'Editing' },

    { keys: 'Double click', description: 'Edit text where it sits', group: 'Canvas' },
    { keys: 'Right click', description: 'Actions for the node under the pointer', group: 'Canvas' },
    { keys: 'Ctrl Enter', description: 'Finish an inline edit', group: 'Canvas' },
    { keys: 'Esc', description: 'Abandon an inline edit', group: 'Canvas' },
]

/** The shortcuts grouped in the order their groups first appear. */
export function shortcutGroups(): [string, Shortcut[]][] {
    const groups = new Map<string, Shortcut[]>()

    for (const shortcut of SHORTCUTS) {
        const list = groups.get(shortcut.group) ?? []
        list.push(shortcut)
        groups.set(shortcut.group, list)
    }

    return [...groups.entries()]
}
