import { defineStore } from 'pinia'

/**
 * Editor chrome state: which mode the left panel is in, which dock drawer
 * is open, how wide the panel is, which breakpoint is previewed.
 *
 * Deliberately separate from the document store. None of this is content,
 * none of it is patched to the server, and none of it belongs in undo
 * history — mixing it with the document is how a store becomes a god
 * object and how "collapse the panel" ends up in the undo stack.
 *
 * Layout preferences persist per browser: an editor who narrowed the
 * panel meant it for tomorrow too.
 */

export type PanelMode = 'library' | 'inspect'
export type LibraryTab = 'elements' | 'patterns' | 'cloud'
export type InspectTab = 'content' | 'style' | 'advanced'
export type Drawer = 'layers' | 'checks' | 'history' | 'comments' | 'design' | null
export type Breakpoint = 'desktop' | 'tablet' | 'mobile'

const STORAGE_KEY = 'magna-builder-ui'

const MIN_WIDTH = 260
const MAX_WIDTH = 480

interface Persisted {
    panelWidth: number
    panelCollapsed: boolean
    /**
     * Categories the editor closed. Storing the CLOSED set rather than the
     * open one means a newly installed plugin's category arrives expanded —
     * a block nobody can find is a block nobody uses.
     */
    collapsedCategories: string[]
}

interface State extends Persisted {
    mode: PanelMode
    libraryTab: LibraryTab
    inspectTab: InspectTab
    drawer: Drawer
    breakpoint: Breakpoint
    search: string
}

function loadPersisted(): Persisted {
    const fallback: Persisted = { panelWidth: 300, panelCollapsed: false, collapsedCategories: [] }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY)
        if (raw === null) {
            return fallback
        }
        const parsed = JSON.parse(raw) as Partial<Persisted>

        return {
            panelWidth: clampWidth(Number(parsed.panelWidth ?? fallback.panelWidth)),
            panelCollapsed: parsed.panelCollapsed === true,
            collapsedCategories: Array.isArray(parsed.collapsedCategories)
                ? parsed.collapsedCategories.filter((entry): entry is string => typeof entry === 'string')
                : [],
        }
    } catch {
        // A corrupt or unavailable store must never stop the editor opening.
        return fallback
    }
}

export function clampWidth(width: number): number {
    if (!Number.isFinite(width)) {
        return 300
    }

    return Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, Math.round(width)))
}

export const useUiStore = defineStore('ui', {
    state: (): State => ({
        mode: 'library',
        libraryTab: 'elements',
        inspectTab: 'content',
        drawer: null,
        breakpoint: 'desktop',
        search: '',
        ...loadPersisted(),
    }),

    getters: {
        categoryOpen: (state) => (category: string): boolean =>
            !state.collapsedCategories.includes(category),
    },

    actions: {
        /**
         * Selecting a node moves the panel to its settings, as Elementor
         * does. A collapsed panel opens: the editor asked to see something.
         */
        inspect(tab: InspectTab = 'content'): void {
            this.mode = 'inspect'
            this.inspectTab = tab
            this.panelCollapsed = false
            this.persist()
        },

        /** Back to the element library, clearing the last search. */
        browse(tab?: LibraryTab): void {
            this.mode = 'library'
            this.search = ''
            if (tab !== undefined) {
                this.libraryTab = tab
            }
        },

        toggleDrawer(drawer: Exclude<Drawer, null>): void {
            this.drawer = this.drawer === drawer ? null : drawer
        },

        closeDrawer(): void {
            this.drawer = null
        },

        toggleCategory(category: string): void {
            const closed = new Set(this.collapsedCategories)
            if (closed.has(category)) {
                closed.delete(category)
            } else {
                closed.add(category)
            }
            this.collapsedCategories = [...closed]
            this.persist()
        },

        setPanelWidth(width: number): void {
            this.panelWidth = clampWidth(width)
            this.persist()
        },

        togglePanel(): void {
            this.panelCollapsed = !this.panelCollapsed
            this.persist()
        },

        persist(): void {
            try {
                window.localStorage.setItem(
                    STORAGE_KEY,
                    JSON.stringify({
                        panelWidth: this.panelWidth,
                        panelCollapsed: this.panelCollapsed,
                        collapsedCategories: this.collapsedCategories,
                    }),
                )
            } catch {
                // Private mode, quota, whatever — preferences are a nicety.
            }
        },
    },
})
