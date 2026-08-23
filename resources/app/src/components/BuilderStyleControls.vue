<script setup lang="ts">
import { computed } from 'vue'

import { declaredAt, effectiveAt, inheritsAt, type Breakpoint } from '../document/responsive'
import type { StyleControl } from '../document/types'
import { useDocumentStore } from '../stores/document'

/**
 * The Style tab's controls, drawn from the table the server ships.
 *
 * This component knows how to render a control type; it does not know
 * which controls exist, what CSS they emit, or which of them a section
 * versus a column may use. That is the renderer's business, and it arrives
 * in the bootstrap payload — so adding a style key is a server change and
 * nothing here needs touching.
 *
 * Editing follows the canvas's device preview: with Tablet selected, a
 * control writes the tablet value only. A control showing a value it
 * inherited from a wider screen says so and offers to give it back, so
 * "why is this greyed out" and "how do I undo this override" both have
 * visible answers.
 */

const props = defineProps<{
    controls: StyleControl[]
    /** The node's current `settings.style`, or an empty object. */
    style: Record<string, unknown>
    canEdit: boolean
    /** The device being previewed; edits land on this breakpoint. */
    breakpoint: Breakpoint
}>()

defineEmits<{ set: [key: string, value: string] }>()

const store = useDocumentStore()

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

/** What the control shows: this breakpoint's value, or the inherited one. */
/**
 * The theme's colour tokens, as values a control can write.
 *
 * This is the answer to per-node dark mode, and it is deliberately NOT a
 * second sentinel beside `$responsive`. A hex frozen into a document has
 * one reading and can never have two; a token has both, because the
 * palette carries both. Adding a `$scheme` axis would double every style
 * key (breakpoint x scheme), every emitter and every control — which is
 * precisely the duplicated, unmanageable styling this design set out to
 * avoid. Making the flipping value as easy to pick as the frozen one
 * solves the same problem with one axis instead of two.
 */
const colorTokens = computed(() =>
    Object.keys(store.tokens)
        .filter((name) => name.startsWith('--color-'))
        .map((name) => ({ name, label: name.replace('--color-', '').replace(/-/g, ' ') })),
)

function valueOf(control: StyleControl): string {
    return effectiveAt(props.style[control.key], props.breakpoint)
}

/** Whether this control is showing a value from a wider screen. */
function inherited(control: StyleControl): boolean {
    return inheritsAt(props.style[control.key], props.breakpoint)
}

/** Whether this breakpoint has an override worth offering to remove. */
function overridden(control: StyleControl): boolean {
    return props.breakpoint !== 'base' && declaredAt(props.style[control.key], props.breakpoint) !== ''
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

        <!--
            An explicit `for` rather than a wrapping label alone: a colour
            control holds two inputs, and implicit association leaves it
            ambiguous which one the label names.
        -->
        <label
            v-for="control in entries"
            :key="control.key"
            class="styles__field"
            :class="{ 'is-inherited': inherited(control) }"
            :for="`style-${control.key}`"
        >
            <span>
                {{ control.label }}
                <small v-if="inherited(control)" :title="`Inherited from the wider screen`">↑</small>
            </span>

            <select
                v-if="control.control === 'select'"
                :id="`style-${control.key}`"
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
                    :id="`style-${control.key}`"
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
                    @change="$emit('set', control.key, ($event.target as HTMLInputElement).value)"
                />
                <!-- A token follows the palette, including into dark mode.
                     A hex cannot, which is why this sits beside it. -->
                <select
                    v-if="colorTokens.length > 0"
                    class="styles__tokenpick"
                    :disabled="!canEdit"
                    :aria-label="`Use a theme colour for ${control.label}`"
                    :value="''"
                    @change="
                        $emit('set', control.key, `var(${($event.target as HTMLSelectElement).value})`);
                        ($event.target as HTMLSelectElement).value = ''
                    "
                >
                    <option value="" disabled selected>🎨</option>
                    <option v-for="token in colorTokens" :key="token.name" :value="token.name">
                        {{ token.label }}
                    </option>
                </select>
            </span>

            <input
                v-else
                :id="`style-${control.key}`"
                type="text"
                placeholder="e.g. 24px"
                :value="valueOf(control)"
                :disabled="!canEdit"
                @change="$emit('set', control.key, ($event.target as HTMLInputElement).value)"
            />

            <button
                v-if="overridden(control)"
                type="button"
                class="styles__reset"
                :disabled="!canEdit"
                title="Reset to the wider screen's value"
                :aria-label="`Reset ${control.label} to the inherited value`"
                @click="$emit('set', control.key, '')"
            >
                ↺
            </button>
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

.styles__tokenpick {
    width: 34px;
    flex: 0 0 auto;
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

/* An inherited value is real — it is what the visitor sees — so it is
   shown, not blanked; dimmed only to say "nothing here overrides it". */
.styles__field.is-inherited input,
.styles__field.is-inherited select {
    opacity: 0.65;
}

.styles__reset {
    flex: 0 0 auto;
    width: 20px;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: transparent;
    color: inherit;
    font: inherit;
    opacity: 0.7;
    cursor: pointer;
}

.styles__reset:hover:not(:disabled) {
    opacity: 1;
}
</style>
