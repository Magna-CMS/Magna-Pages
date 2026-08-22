<script setup lang="ts">
import { ref } from 'vue'

import BuilderStructurePicker from './BuilderStructurePicker.vue'

/**
 * The Elementor affordance: where the page ends, the way forward begins.
 *
 * A dashed area after the last section — or filling an empty page — with
 * the three ways content arrives: add a row (the + opens the structure
 * picker in place), pull something from the cloud library (the folder
 * opens the panel on its Cloud tab), or drag an element straight in. The
 * drop-below-the-last-section path already appends, so the hint is a
 * statement of fact, not an aspiration.
 *
 * Drawn by the PARENT, never injected into the canvas frame: the frame is
 * the production render, and an affordance living inside it would be the
 * builder's markup shipping to visitors.
 */

defineProps<{ canStructure: boolean }>()

const emit = defineEmits<{
    addSection: [spans: number[]]
    browse: []
}>()

/** The + reveals the picker in place, as Elementor does. */
const picking = ref(false)

function pick(spans: number[]) {
    picking.value = false
    emit('addSection', spans)
}
</script>

<template>
    <section class="addhere" aria-label="Add to the end of the page">
        <div v-if="!picking" class="addhere__actions">
            <button
                type="button"
                class="addhere__add"
                :disabled="!canStructure"
                :title="canStructure ? 'Add a section' : 'Needs the layout permission'"
                aria-label="Add a section"
                @click="picking = true"
            >
                +
            </button>
            <button
                type="button"
                class="addhere__browse"
                :disabled="!canStructure"
                :title="canStructure ? 'Browse the cloud library' : 'Needs the layout permission'"
                aria-label="Browse the cloud library"
                @click="$emit('browse')"
            >
                📁
            </button>
        </div>

        <div v-else class="addhere__picker">
            <BuilderStructurePicker :disabled="!canStructure" @pick="pick" />
            <button type="button" class="addhere__cancel" @click="picking = false">Cancel</button>
        </div>

        <p class="addhere__hint">Drag an element here, or add a section</p>
    </section>
</template>

<style scoped>
.addhere {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 22px 16px;
    border: 1px dashed var(--builder-border);
    border-radius: 6px;
    background: color-mix(in srgb, var(--builder-surface) 92%, transparent);
}

.addhere__actions {
    display: flex;
    gap: 8px;
}

.addhere__add,
.addhere__browse {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 50%;
    color: #fff;
    font: inherit;
    font-size: 17px;
    line-height: 1;
    cursor: pointer;
}

.addhere__add {
    background: var(--builder-accent);
}

.addhere__browse {
    background: #4a4f5c;
    font-size: 14px;
}

.addhere__add:hover:not(:disabled),
.addhere__browse:hover:not(:disabled) {
    filter: brightness(1.15);
}

.addhere__add:disabled,
.addhere__browse:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.addhere__picker {
    width: min(420px, 100%);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.addhere__cancel {
    align-self: center;
    padding: 2px 10px;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    opacity: 0.7;
    cursor: pointer;
}

.addhere__hint {
    margin: 0;
    font-size: 12px;
    opacity: 0.6;
}
</style>
