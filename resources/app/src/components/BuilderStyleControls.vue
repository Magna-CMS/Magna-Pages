<script setup lang="ts">
import { computed } from 'vue'

import type { StyleControl } from '../document/types'

/**
 * The Style tab's controls, drawn from the table the server ships.
 *
 * This component knows how to render a control type; it does not know
 * which controls exist, what CSS they emit, or which of them a section
 * versus a column may use. That is the renderer's business, and it arrives
 * in the bootstrap payload — so adding a style key is a server change and
 * nothing here needs touching.
 */

const props = defineProps<{
    controls: StyleControl[]
    /** The node's current `settings.style`, or an empty object. */
    style: Record<string, unknown>
    canEdit: boolean
}>()

defineEmits<{ set: [key: string, value: string] }>()

/** Controls in the groups the server named, in the order it named them. */
const groups = computed(() => {
    const grouped = new Map<string, StyleControl[]>()

    for (const control of props.controls) {
        const list = grouped.get(control.group) ?? []
        list.push(control)
        grouped.set(control.group, list)
    }

    return [...grouped.entries()]
})

function valueOf(control: StyleControl): string {
    const value = props.style[control.key]

    return typeof value === 'string' || typeof value === 'number' ? String(value) : ''
}

/**
 * A colour input cannot hold a token reference, so the field stays text
 * and the swatch sits beside it: `var(--color-primary)` is the value an
 * editor most often wants, and a picker that could not express it would
 * push people to paste hex codes the theme cannot restyle.
 */
function swatch(control: StyleControl): string {
    const value = valueOf(control)

    return /^#[0-9a-f]{3,8}$/i.test(value) ? value : '#000000'
}
</script>

<template>
    <section v-for="[group, entries] in groups" :key="group" class="styles__group">
        <h3 class="styles__heading">{{ group }}</h3>

        <label v-for="control in entries" :key="control.key" class="styles__field">
            <span>{{ control.label }}</span>

            <select
                v-if="control.control === 'select'"
                :value="valueOf(control)"
                :disabled="!canEdit"
                @change="$emit('set', control.key, ($event.target as HTMLSelectElement).value)"
            >
                <option v-for="option in control.options" :key="option" :value="option">
                    {{ option === '' ? 'Default' : option }}
                </option>
            </select>

            <span v-else-if="control.control === 'color'" class="styles__color">
                <input
                    type="text"
                    placeholder="var(--color-primary)"
                    :value="valueOf(control)"
                    :disabled="!canEdit"
                    @change="$emit('set', control.key, ($event.target as HTMLInputElement).value)"
                />
                <input
                    type="color"
                    :value="swatch(control)"
                    :disabled="!canEdit"
                    :aria-label="`${control.label} colour picker`"
                    @input="$emit('set', control.key, ($event.target as HTMLInputElement).value)"
                />
            </span>

            <input
                v-else
                type="text"
                placeholder="e.g. 24px"
                :value="valueOf(control)"
                :disabled="!canEdit"
                @change="$emit('set', control.key, ($event.target as HTMLInputElement).value)"
            />
        </label>
    </section>
</template>

<style scoped>
.styles__group {
    margin-bottom: 12px;
}

.styles__heading {
    margin: 0 0 6px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.6;
}

.styles__field {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 5px;
    font-size: 12px;
}

.styles__field > span:first-child {
    flex: 0 0 42%;
    opacity: 0.85;
}

.styles__field input,
.styles__field select {
    flex: 1;
    min-width: 0;
    padding: 4px 6px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 12px;
}

.styles__field input:disabled,
.styles__field select:disabled {
    opacity: 0.5;
}

.styles__color {
    display: flex;
    flex: 1;
    gap: 4px;
    min-width: 0;
}

.styles__color input[type='color'] {
    flex: 0 0 30px;
    padding: 1px;
}
</style>
