<script setup lang="ts">
import type { TagMatch } from '../document/tagAutocomplete'

/**
 * The tags matching what is being typed, under the field they belong to.
 *
 * Its own component because two kinds of field offer it — the one-line text
 * input and the textarea a richtext value is edited in — and the second copy
 * of a list with keyboard state in it is the one that drifts.
 *
 * Deliberately dumb: which key does what lives in useTagCompletion, because
 * the field owns the caret and this owns nothing.
 */
defineProps<{
    matches: TagMatch[]
    active: number
    query: string
}>()

const emit = defineEmits<{ pick: [handle: string] }>()

/**
 * `mousedown`, not `click`.
 *
 * A click fires after the field has already lost focus, and blur closes the
 * list — so by the time the click landed there would be nothing to click.
 */
function choose(handle: string): void {
    emit('pick', handle)
}
</script>

<template>
    <ul
        class="inspector__tagsuggest"
        role="listbox"
        :aria-label="`Dynamic tags matching ${query}`"
    >
        <li
            v-for="(match, position) in matches"
            :key="match.handle"
            role="option"
            :aria-selected="position === active"
            :class="{ 'inspector__tagsuggest--active': position === active }"
            @mousedown.prevent="choose(match.handle)"
        >
            <span class="inspector__tagsuggest-handle">{{ match.handle }}</span>
            <span class="inspector__tagsuggest-label">{{ match.label }}</span>
        </li>
    </ul>
</template>
