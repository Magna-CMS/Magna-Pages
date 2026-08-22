<script setup lang="ts">
import { ref } from 'vue'

import BuilderStructurePicker from './BuilderStructurePicker.vue'

/**
 * The Elementor affordance: where the page ends, the way forward begins.
 *
 * A dashed area after the page — or filling an empty one — with the three
 * ways content arrives: add a row (the + opens the structure picker in
 * place), pull something from the cloud library (the folder opens the
 * panel on its Cloud tab), or drag an element straight in. The
 * drop-below-the-last-section path already appends, so the hint is a
 * statement of fact, not an aspiration.
 *
 * Styled for the surface it actually sits on: the WHITE page inside the
 * canvas, not the dark builder chrome. A dark slab there reads as a hole
 * in the page; a light dashed area reads as Elementor has taught every
 * page-builder user to read it — "this is where more page goes".
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
                <svg viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button
                type="button"
                class="addhere__browse"
                :disabled="!canStructure"
                :title="canStructure ? 'Browse the cloud library' : 'Needs the layout permission'"
                aria-label="Browse the cloud library"
                @click="$emit('browse')"
            >
                <svg viewBox="0 0 16 16" aria-hidden="true">
                    <path
                        d="M2 4.5A1.5 1.5 0 0 1 3.5 3h2.6l1.4 1.6h5A1.5 1.5 0 0 1 14 6.1v5.4a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 11.5v-7z"
                        fill="currentColor"
                    />
                </svg>
            </button>
        </div>

        <div v-else class="addhere__picker">
            <BuilderStructurePicker :disabled="!canStructure" @pick="pick" />
            <button type="button" class="addhere__cancel" @click="picking = false">Cancel</button>
        </div>

        <p v-if="!picking" class="addhere__hint">Drag an element here</p>
    </section>
</template>

<style scoped>
/* Light on purpose: this sits on the rendered page, not on builder chrome. */
.addhere {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 110px;
    padding: 18px 16px;
    border: 1px dashed #c9cdd4;
    border-radius: 4px;
    background: transparent;
}

.addhere__actions {
    display: flex;
    gap: 10px;
}

.addhere__add,
.addhere__browse {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 50%;
    font: inherit;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.addhere__add svg,
.addhere__browse svg {
    width: 15px;
    height: 15px;
}

.addhere__add {
    border: 0;
    background: var(--builder-accent);
    color: #fff;
}

.addhere__browse {
    border: 1px solid #d5d8dc;
    background: #fff;
    color: #6b7280;
}

.addhere__add:hover:not(:disabled),
.addhere__browse:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgb(0 0 0 / 18%);
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
    padding: 10px;
    border-radius: 6px;
    /* The picker's preset bars are designed against builder chrome, so
       they keep their dark plate here rather than washing out on white. */
    background: var(--builder-surface);
    box-shadow: 0 6px 24px rgb(0 0 0 / 25%);
}

.addhere__cancel {
    align-self: center;
    padding: 2px 10px;
    border: 0;
    background: transparent;
    color: #c9cdd4;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}

.addhere__hint {
    margin: 0;
    font-size: 12.5px;
    color: #a4afb7;
    user-select: none;
}
</style>
