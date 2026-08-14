/**
 * The command palette's brain: what actions exist right now, and which
 * match a query. Pure functions — the palette component renders and
 * dispatches, it never decides.
 *
 * Actions are built fresh per open from the live context, so the list is
 * always true: no "Publish" entry for an actor who cannot publish, no
 * "Insert block" entries whose insertion the server would refuse. The
 * palette offering an action IS a claim it will work.
 */

export interface PaletteAction {
    id: string
    label: string
    /** Group heading shown in the list. */
    group: string
    /** Extra words the filter matches that the label does not show. */
    keywords?: string
    run: () => void
}

export interface PaletteContext {
    capabilities: { content: boolean; structure: boolean; style: boolean; publish: boolean }
    lockMine: boolean
    hasSelection: boolean
    targetParent: string | null
    blocks: { handle: string; label: string; requiresPermission: string | null }[]
    patterns: { id: string; name: string; kind: string }[]
    breakpoints: string[]
    handlers: {
        addBlock: (handle: string) => void
        addSection: () => void
        insertPattern: (id: string) => void
        setBreakpoint: (device: string) => void
        undo: () => void
        redo: () => void
        deleteSelection: () => void
        publish: () => void
        savePattern: () => void
    }
}

export function buildActions(context: PaletteContext): PaletteAction[] {
    const actions: PaletteAction[] = []
    const { capabilities, handlers } = context

    if (capabilities.structure) {
        actions.push({
            id: 'add-section',
            label: 'Add section',
            group: 'Structure',
            keywords: 'new row',
            run: handlers.addSection,
        })

        if (context.targetParent !== null) {
            for (const block of context.blocks) {
                // Blocks gated by a permission the actor lacks are omitted
                // entirely here (unlike the Add panel, which teaches by
                // greying) — a palette is for doing, and a result that only
                // explains why it won't is noise between the user and the
                // result they typed for.
                if (block.requiresPermission && !capabilities.style) {
                    continue
                }
                actions.push({
                    id: `add-block-${block.handle}`,
                    label: `Insert ${block.label}`,
                    group: 'Insert',
                    keywords: `block add ${block.handle}`,
                    run: () => handlers.addBlock(block.handle),
                })
            }
        }

        for (const pattern of context.patterns) {
            if (pattern.kind === 'block' && context.targetParent === null) {
                continue
            }
            actions.push({
                id: `pattern-${pattern.id}`,
                label: `Insert pattern: ${pattern.name}`,
                group: 'Insert',
                keywords: 'library my',
                run: () => handlers.insertPattern(pattern.id),
            })
        }

        if (context.hasSelection) {
            actions.push({
                id: 'delete-selection',
                label: 'Delete selection',
                group: 'Structure',
                keywords: 'remove',
                run: handlers.deleteSelection,
            })
            actions.push({
                id: 'save-pattern',
                label: 'Save selection as pattern',
                group: 'Structure',
                keywords: 'library',
                run: handlers.savePattern,
            })
        }
    }

    for (const device of context.breakpoints) {
        actions.push({
            id: `breakpoint-${device}`,
            label: `Preview: ${device}`,
            group: 'View',
            keywords: 'breakpoint responsive viewport',
            run: () => handlers.setBreakpoint(device),
        })
    }

    actions.push(
        { id: 'undo', label: 'Undo', group: 'Edit', keywords: 'revert', run: handlers.undo },
        { id: 'redo', label: 'Redo', group: 'Edit', run: handlers.redo },
    )

    if (capabilities.publish && context.lockMine) {
        actions.push({
            id: 'publish',
            label: 'Publish page',
            group: 'Page',
            keywords: 'ship live',
            run: handlers.publish,
        })
    }

    return actions
}

/**
 * Subsequence match: every query character appears in order. "ihe" finds
 * "Insert Heading"; earlier and denser matches rank higher. Case-insensitive.
 */
export function filterActions(actions: PaletteAction[], query: string): PaletteAction[] {
    const needle = query.trim().toLowerCase()
    if (needle === '') {
        return actions
    }

    return actions
        .map((action) => ({ action, score: score(needle, `${action.label} ${action.keywords ?? ''}`.toLowerCase()) }))
        .filter((entry) => entry.score !== null)
        .sort((a, b) => (a.score as number) - (b.score as number))
        .map((entry) => entry.action)
}

/** Lower is better; null is no match. */
function score(needle: string, haystack: string): number | null {
    let position = -1
    let spread = 0

    for (const char of needle) {
        const found = haystack.indexOf(char, position + 1)
        if (found === -1) {
            return null
        }
        if (position !== -1) {
            spread += found - position - 1
        }
        position = found
    }

    // Prefer matches that start earlier and sit closer together.
    return haystack.indexOf(needle[0]) + spread
}
