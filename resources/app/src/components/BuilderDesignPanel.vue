<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import type { Capabilities } from '../document/types'

/**
 * The Design tab: the active theme's tokens, retunable per site.
 *
 * Typing previews instantly through the CSS-variable path (no server round
 * trip); Save persists. The distinction is deliberate — a palette change
 * restyles every page on the site, so the moment of commitment should be a
 * button, not a keystroke.
 */

const props = defineProps<{
    theme: Record<string, string>
    overrides: Record<string, string>
    capabilities: Capabilities
    saving: boolean
}>()

const emit = defineEmits<{
    preview: [tokens: Record<string, string>]
    save: [tokens: Record<string, string>]
}>()

/** Local edit buffer: override values keyed by variable name. */
const draft = ref<Record<string, string>>({})

watch(
    () => props.overrides,
    (next) => (draft.value = { ...next }),
    { immediate: true },
)

const variables = computed(() => Object.entries(props.theme))

const dirty = computed(() => {
    const keys = new Set([...Object.keys(draft.value), ...Object.keys(props.overrides)])

    return [...keys].some((key) => (draft.value[key] ?? '') !== (props.overrides[key] ?? ''))
})

function effectiveValue(name: string): string {
    return draft.value[name] ?? props.theme[name] ?? ''
}

function isColor(name: string, value: string): boolean {
    return name.startsWith('--color-') || /^#[0-9a-fA-F]{3,8}$/.test(value)
}

function onInput(name: string, value: string) {
    if (value === '' || value === props.theme[name]) {
        // Cleared, or set back to the theme's own value: no override.
        delete draft.value[name]
    } else {
        draft.value[name] = value
    }

    emit('preview', { [name]: value === '' ? (props.theme[name] ?? '') : value })
}

function onSave() {
    emit('save', { ...draft.value })
}
</script>

<template>
    <section class="design">
        <h2 class="design__heading">Design</h2>

        <p v-if="variables.length === 0" class="design__empty">
            The active theme declares no tokens.
        </p>

        <div v-for="[name, themeValue] in variables" :key="name" class="design__token">
            <label :for="`token${name}`">
                {{ name.replace(/^--/, '') }}
                <button
                    v-if="draft[name] !== undefined"
                    type="button"
                    class="design__reset"
                    :title="`Back to ${themeValue}`"
                    @click="onInput(name, '')"
                >
                    reset
                </button>
            </label>

            <span class="design__row">
                <input
                    v-if="isColor(name, themeValue)"
                    class="design__swatch"
                    type="color"
                    :value="effectiveValue(name)"
                    :disabled="!capabilities.style"
                    @input="onInput(name, ($event.target as HTMLInputElement).value)"
                />
                <input
                    :id="`token${name}`"
                    type="text"
                    :value="draft[name] ?? ''"
                    :placeholder="themeValue"
                    :disabled="!capabilities.style"
                    @change="onInput(name, ($event.target as HTMLInputElement).value)"
                />
            </span>
        </div>

        <button
            v-if="variables.length > 0"
            type="button"
            class="design__save"
            :disabled="!capabilities.style || !dirty || saving"
            @click="onSave"
        >
            {{ saving ? 'Saving…' : 'Save styles' }}
        </button>

        <p v-if="!capabilities.style" class="design__empty">
            Changing site styles needs the design permission.
        </p>
    </section>
</template>

<style scoped>
.design__heading {
    margin: 16px 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.design__token {
    margin-bottom: 10px;
}

.design__token label {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 3px;
    font-size: 12px;
    opacity: 0.8;
}

.design__reset {
    border: 0;
    background: none;
    color: var(--builder-accent);
    font-size: 11px;
    cursor: pointer;
}

.design__row {
    display: flex;
    gap: 6px;
}

.design__row input[type='text'] {
    flex: 1;
    padding: 5px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 12px;
}

.design__swatch {
    width: 34px;
    height: 30px;
    padding: 2px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
}

.design__save {
    width: 100%;
    margin-top: 4px;
    padding: 6px;
    border: 0;
    border-radius: 4px;
    background: var(--builder-accent);
    color: #fff;
    font: inherit;
    cursor: pointer;
}

.design__save:disabled {
    opacity: 0.4;
    cursor: default;
}

.design__empty {
    font-size: 12px;
    opacity: 0.7;
}
</style>
