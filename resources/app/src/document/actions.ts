import type { NodeKind } from './locate'

/**
 * What a node offers, and why an option is greyed rather than missing.
 *
 * One table, read by the canvas context menu and by the navigator's row
 * menu. Every canvas action existing in the navigator is the accessibility
 * promise this builder makes (ATAG 2.0), and it only holds while both are
 * generated from the same place.
 *
 * A disabled entry with a reason beats a hidden one: an editor who cannot
 * find "duplicate" assumes it is missing, while one who sees it greyed out
 * learns what is in their way.
 */

export type NodeActionKey =
    | 'moveUp'
    | 'moveDown'
    | 'duplicate'
    | 'rename'
    | 'copy'
    | 'paste'
    | 'copyStyles'
    | 'pasteStyles'
    | 'savePattern'
    | 'delete'

export interface NodeAction {
    key: NodeActionKey
    label: string
    enabled: boolean
    hint?: string
}

export interface NodeActionContext {
    kind: NodeKind
    /** pages.layout — structural edits. */
    canStructure: boolean
    /** pages.content — the tier that may save a pattern or name a node. */
    canContent: boolean
    /** pages.design — the tier that may write styles. */
    canStyle: boolean
    /** Whether this editor holds the lock; without it nothing saves. */
    holdsLock: boolean
    /** Whether anything is on the clipboard to paste. */
    hasClipboard: boolean
    /** Whether a style set has been copied from another node. */
    hasStyles: boolean
    /** Position among siblings, for the move options. */
    isFirst: boolean
    isLast: boolean
}

export function nodeActions(context: NodeActionContext): NodeAction[] {
    const structural = context.canStructure && context.holdsLock
    const lockHint = context.holdsLock ? undefined : 'Another editor holds this page'
    const structureHint = context.canStructure ? lockHint : 'Needs the layout permission'

    // Sections reorder among sections, blocks among their column's blocks.
    // A column's position is the row's shape, edited with the span controls
    // rather than by nudging one column past another.
    const movable = context.kind === 'section' || context.kind === 'block'

    return [
        {
            key: 'moveUp',
            label: 'Move up',
            enabled: structural && movable && !context.isFirst,
            hint: movable ? structureHint : 'Columns are ordered by the row layout',
        },
        {
            key: 'moveDown',
            label: 'Move down',
            enabled: structural && movable && !context.isLast,
            hint: movable ? structureHint : 'Columns are ordered by the row layout',
        },
        {
            key: 'duplicate',
            label: 'Duplicate',
            enabled: structural && context.kind !== 'column',
            hint: context.kind === 'column' ? 'Add a column instead' : structureHint,
        },
        {
            key: 'rename',
            label: 'Rename',
            // Naming a node is editorial, not structural: it never reaches
            // the page, so the content tier is the right gate.
            enabled: context.canContent && context.holdsLock,
            hint: context.canContent ? lockHint : 'Needs the content permission',
        },
        { key: 'copy', label: 'Copy', enabled: true },
        {
            key: 'paste',
            label: 'Paste',
            enabled: structural && context.hasClipboard,
            hint: context.hasClipboard ? structureHint : 'Nothing copied yet',
        },
        {
            key: 'copyStyles',
            label: 'Copy styles',
            // Only the kinds that HAVE a style set: a block's styling lives
            // in its own fields, not in settings.style.
            enabled: context.kind !== 'block',
            hint: context.kind === 'block' ? 'Blocks style through their own fields' : undefined,
        },
        {
            key: 'pasteStyles',
            label: 'Paste styles',
            enabled: context.canStyle && context.holdsLock && context.hasStyles && context.kind !== 'block',
            hint: context.hasStyles
                ? context.canStyle
                    ? lockHint
                    : 'Needs the design permission'
                : 'No styles copied yet',
        },
        {
            key: 'savePattern',
            label: 'Save as pattern',
            enabled: context.canContent && context.kind !== 'column',
            hint: context.kind === 'column' ? 'Save the whole section instead' : undefined,
        },
        { key: 'delete', label: 'Delete', enabled: structural, hint: structureHint },
    ]
}
