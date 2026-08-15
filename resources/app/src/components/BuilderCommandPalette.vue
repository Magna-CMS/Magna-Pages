<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

import { filterActions, type PaletteAction } from '../palette'

/**
 * The command palette surface. All decisions live in palette.ts; this
 * renders a filtered list and moves a highlight. Fully keyboard-driven:
 * arrows move, Enter runs, Escape closes — and running always closes,
 * because a palette that lingers after acting makes every action feel
 * unfinished.
 */

const props = defineProps<{
    open: boolean
    actions: PaletteAction[]
}>()

const emit = defineEmits<{ close: [] }>()

const query = ref('')
const highlighted = ref(0)
const input = ref<HTMLInputElement | null>(null)

const matches = computed(() => filterActions(props.actions, query.value))

watch(
    () => props.open,
    async (open) => {
        if (open) {
            query.value = ''
            highlighted.value = 0
            await nextTick()
            input.value?.focus()
        }
    },
)

watch(query, () => (highlighted.value = 0))

function run(action: PaletteAction) {
    emit('close')
    action.run()
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close')

        return
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        highlighted.value = Math.min(highlighted.value + 1, matches.value.length - 1)

        return
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault()
        highlighted.value = Math.max(highlighted.value - 1, 0)

        return
    }
    if (event.key === 'Enter' && matches.value[highlighted.value]) {
        event.preventDefault()
        run(matches.value[highlighted.value])
    }
}
</script>

<template>
    <div v-if="open" class="palette__backdrop" @click.self="$emit('close')">
        <div class="palette" role="dialog" aria-label="Command palette">
            <input
                ref="input"
                v-model="query"
                class="palette__input"
                type="text"
                placeholder="Type a command…"
                aria-label="Search commands"
                @keydown="onKeydown"
            />

            <ul class="palette__list" role="listbox">
                <li
                    v-for="(action, index) in matches"
                    :key="action.id"
                    class="palette__item"
                    :class="{ 'is-highlighted': index === highlighted }"
                    role="option"
                    :aria-selected="index === highlighted"
                    @mouseenter="highlighted = index"
                    @click="run(action)"
                >
                    <span>{{ action.label }}</span>
                    <small>{{ action.group }}</small>
                </li>

                <li v-if="matches.length === 0" class="palette__empty">No matching command.</li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.palette__backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding-top: 12vh;
    background: rgb(0 0 0 / 45%);
}

.palette {
    width: min(520px, 92vw);
    border: 1px solid var(--builder-border);
    border-radius: 8px;
    background: var(--builder-surface);
    box-shadow: 0 18px 50px rgb(0 0 0 / 50%);
    overflow: hidden;
}

.palette__input {
    width: 100%;
    padding: 12px 14px;
    border: 0;
    border-bottom: 1px solid var(--builder-border);
    background: transparent;
    color: inherit;
    font: inherit;
}

/* The palette focuses this on open, so its ring looked like noise and was
   removed outright — which also took it away from anyone who tabs back to
   it. Drawn on the border instead: visible where it belongs, and quiet
   when focus arrived by opening the dialog rather than by tabbing. */
.palette__input:focus-visible {
    outline: none;
    border-bottom-color: var(--builder-accent);
    box-shadow: inset 0 -2px 0 var(--builder-accent);
}

.palette__list {
    max-height: 320px;
    margin: 0;
    padding: 4px;
    list-style: none;
    overflow-y: auto;
}

.palette__item {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    padding: 7px 10px;
    border-radius: 5px;
    cursor: pointer;
}

.palette__item.is-highlighted {
    background: var(--builder-accent);
    color: #fff;
}

.palette__item small {
    font-size: 11px;
    opacity: 0.65;
}

.palette__empty {
    padding: 14px;
    font-size: 13px;
    opacity: 0.6;
}
</style>
