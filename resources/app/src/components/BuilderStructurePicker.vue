<script setup lang="ts">
import { COLUMN_PRESETS } from '../document/edits'

/**
 * The column-structure presets, drawn as proportional bars so the shape
 * reads before the label.
 *
 * One component, used by the library's Add-section group and by the
 * canvas's add-here affordance — two pickers that drifted apart would
 * offer two different ideas of what a row can be.
 */

defineProps<{ disabled: boolean }>()

defineEmits<{ pick: [spans: number[]] }>()

function barStyle(span: number) {
    return { flex: `${span} 1 0%` }
}
</script>

<template>
    <div class="structures">
        <button
            v-for="preset in COLUMN_PRESETS"
            :key="preset.label"
            type="button"
            class="structures__preset"
            :disabled="disabled"
            :aria-label="`Add section: ${preset.label}`"
            :title="preset.label"
            @click="$emit('pick', preset.spans)"
        >
            <span class="structures__bars" aria-hidden="true">
                <span
                    v-for="(span, index) in preset.spans"
                    :key="index"
                    class="structures__bar"
                    :style="barStyle(span)"
                />
            </span>
            <small>{{ preset.label }}</small>
        </button>
    </div>
</template>

<style scoped>
.structures {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
}

.structures__preset {
    padding: 5px 3px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.structures__preset:hover:not(:disabled) {
    border-color: var(--builder-accent);
}

.structures__preset:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.structures__preset small {
    display: block;
    margin-top: 3px;
    font-size: 9px;
    opacity: 0.7;
    white-space: nowrap;
}

.structures__bars {
    display: flex;
    gap: 2px;
    height: 16px;
}

.structures__bar {
    border-radius: 2px;
    background: color-mix(in srgb, var(--builder-accent) 45%, transparent);
}
</style>
