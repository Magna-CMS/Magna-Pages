import { ref } from 'vue'

import { completeTag, matchTags, openTagQuery, type OpenTagQuery, type TagMatch } from './tagAutocomplete'

/**
 * The typing half of inline dynamic tags: type `{tag:`, see what matches,
 * press Enter.
 *
 * Kept out of the inspector component because the awkward parts are not
 * about rendering — where the caret is, which key does what, what closes the
 * list — and a component that owned them would be a component nobody could
 * test without mounting the whole panel.
 *
 * The field element is the source of truth for the value while a completion
 * is open, not the store. The inspector commits a text field on `change`
 * (blur or Enter), so mid-typing the store still holds what was there before
 * the writer started the token — asking it would complete against a stale
 * string and delete the characters typed since.
 */
export interface TagCompletionState {
    /** Which field the list belongs to, so only that one draws it. */
    handle: string
    matches: TagMatch[]
    index: number
    open: OpenTagQuery
}

export type TextField = HTMLInputElement | HTMLTextAreaElement

export function useTagCompletion(
    sources: () => Record<string, string>,
    commit: (handle: string, value: string) => void,
) {
    const state = ref<TagCompletionState | null>(null)

    /** Recompute from wherever the caret now is. Called on every keystroke. */
    function refresh(handle: string, element: TextField): void {
        const caret = element.selectionStart

        if (typeof caret !== 'number') {
            close()

            return
        }

        const open = openTagQuery(element.value, caret)

        if (open === null) {
            close()

            return
        }

        const matches = matchTags(sources(), open.query)

        if (matches.length === 0) {
            // A query nothing matches is somebody typing a handle we do not
            // have. Closing says so, quietly, rather than showing an empty box.
            close()

            return
        }

        // The highlight stays where it was while the same token narrows, so
        // arrowing down and typing one more character does not jump back to
        // the top under the writer's fingers.
        const index = state.value?.handle === handle && state.value.open.from === open.from
            ? Math.min(state.value.index, matches.length - 1)
            : 0

        state.value = { handle, matches, index, open }
    }

    /**
     * The keys the list owns while it is open, and only those.
     *
     * Returns true when the key was consumed, so the caller knows to stop the
     * event: Enter would otherwise commit the field and Escape would deselect
     * the node, both of which are the wrong answer to "I am choosing a tag".
     */
    function handleKey(handle: string, element: TextField, event: KeyboardEvent): boolean {
        const current = state.value

        if (current === null || current.handle !== handle) {
            return false
        }

        switch (event.key) {
            case 'ArrowDown':
                current.index = (current.index + 1) % current.matches.length

                return true

            case 'ArrowUp':
                current.index = (current.index - 1 + current.matches.length) % current.matches.length

                return true

            case 'Enter':
            case 'Tab':
                accept(handle, element, current.matches[current.index]?.handle ?? '')

                return true

            case 'Escape':
                close()

                return true

            default:
                return false
        }
    }

    /** Write the chosen tag into the field and commit it. */
    function accept(handle: string, element: TextField | null, tag: string): void {
        const current = state.value

        if (current === null || element === null || tag === '') {
            return
        }

        const { value, caret } = completeTag(element.value, tag, current.open)

        element.value = value
        /*
         * The caret is restored on the element rather than left to the
         * re-render. A committed value flows back through the store as a
         * prop, and a field redrawn from a prop puts the caret at the end —
         * which is exactly where a writer completing a tag mid-sentence does
         * not want it.
         */
        element.setSelectionRange(caret, caret)

        close()
        commit(handle, value)
    }

    function close(): void {
        state.value = null
    }

    return { state, refresh, handleKey, accept, close }
}
