<script setup lang="ts">
import { shortcutGroups } from '../shortcuts'

/**
 * The keyboard shortcuts, on demand.
 *
 * Opened with `?` — the convention people already try — and closed with
 * Escape or a click outside. A builder with a command palette, a context
 * menu and inline editing has enough gestures that discovering them by
 * accident is not a plan.
 */

defineEmits<{ close: [] }>()

const groups = shortcutGroups()
</script>

<template>
    <div class="shortcuts" role="dialog" aria-label="Keyboard shortcuts" @click.self="$emit('close')">
        <div class="shortcuts__panel">
            <div class="shortcuts__bar">
                <h2>Keyboard shortcuts</h2>
                <button type="button" aria-label="Close" @click="$emit('close')">✕</button>
            </div>

            <div class="shortcuts__groups">
                <section v-for="[group, entries] in groups" :key="group">
                    <h3>{{ group }}</h3>
                    <dl>
                        <div v-for="entry in entries" :key="`${group}-${entry.keys}`">
                            <dt><kbd>{{ entry.keys }}</kbd></dt>
                            <dd>{{ entry.description }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.shortcuts {
    position: fixed;
    inset: 0;
    z-index: 75;
    display: grid;
    place-items: center;
    background: rgb(0 0 0 / 55%);
}

.shortcuts__panel {
    width: min(560px, 92vw);
    max-height: 80vh;
    overflow-y: auto;
    border: 1px solid var(--builder-border);
    border-radius: 8px;
    background: var(--builder-surface);
}

.shortcuts__bar {
    position: sticky;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid var(--builder-border);
    background: var(--builder-surface);
}

.shortcuts__bar h2 {
    margin: 0;
    font-size: 14px;
}

.shortcuts__bar button {
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.shortcuts__groups {
    padding: 6px 14px 14px;
}

.shortcuts__groups h3 {
    margin: 12px 0 4px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.6;
}

.shortcuts__groups dl {
    margin: 0;
}

.shortcuts__groups dl > div {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 3px 0;
}

.shortcuts__groups dt {
    flex: 0 0 130px;
}

.shortcuts__groups dd {
    margin: 0;
    opacity: 0.85;
}

kbd {
    padding: 1px 6px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    font: inherit;
    font-size: 11px;
}
</style>
