<script setup lang="ts">
import { computed } from 'vue'

import { COLUMN_PRESETS } from '../document/edits'
import type { SectionNode } from '../document/types'

/**
 * Row layout: how many columns this section has and how wide each one is.
 *
 * Spans are edited as the twelfths they are stored as, and the producers
 * rebalance whatever is entered back to twelve — so a row can never be
 * saved half-empty or overflowing, however the numbers are typed.
 */

const props = defineProps<{
    section: SectionNode
    /** Highlighted when the editor is inspecting one column of the row. */
    selectedColumn: string | null
    canEdit: boolean
}>()

defineEmits<{
    addColumn: []
    removeColumn: [columnId: string]
    setSpans: [spans: number[]]
    select: [nodeId: string]
}>()

const columns = computed(() => props.section.columns ?? [])

const spans = computed(() => columns.value.map((column) => column.span))

/** Presets that fit the row as it stands, offered as one-click reshapes. */
const reshapes = computed(() =>
    COLUMN_PRESETS.filter(
        (preset) =>
            preset.spans.length === columns.value.length &&
            preset.spans.join(',') !== spans.value.join(','),
    ),
)

function spansWith(index: number, span: number): number[] {
    const next = [...spans.value]
    next[index] = span

    return next
}
</script>

<template>
    <fieldset class="columns">
        <legend>Columns</legend>

        <ol class="columns__list">
            <li v-for="(column, index) in columns" :key="column.id" class="columns__row">
                <button
                    type="button"
                    class="columns__pick"
                    :class="{ 'is-selected': selectedColumn === column.id }"
                    :title="'Select this column'"
                    @click="$emit('select', column.id)"
                >
                    {{ index + 1 }}
                </button>

                <input
                    type="number"
                    min="1"
                    max="12"
                    :value="column.span"
                    :disabled="!canEdit"
                    :aria-label="`Column ${index + 1} width in twelfths`"
                    @change="
                        $emit(
                            'setSpans',
                            spansWith(index, Number(($event.target as HTMLInputElement).value)),
                        )
                    "
                />

                <button
                    type="button"
                    class="columns__remove"
                    :disabled="!canEdit || columns.length < 2"
                    :title="
                        columns.length < 2
                            ? 'A section keeps at least one column'
                            : 'Remove this column'
                    "
                    :aria-label="`Remove column ${index + 1}`"
                    @click="$emit('removeColumn', column.id)"
                >
                    ×
                </button>
            </li>
        </ol>

        <button
            type="button"
            class="columns__add"
            :disabled="!canEdit || columns.length >= 12"
            :title="columns.length >= 12 ? 'Twelve columns is the grid' : 'Add a column'"
            @click="$emit('addColumn')"
        >
            + Column
        </button>

        <div v-if="reshapes.length > 0" class="columns__presets">
            <button
                v-for="preset in reshapes"
                :key="preset.label"
                type="button"
                :disabled="!canEdit"
                @click="$emit('setSpans', preset.spans)"
            >
                {{ preset.label }}
            </button>
        </div>
    </fieldset>
</template>

<style scoped>
.columns {
    margin-bottom: 12px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    padding: 8px 10px;
}

.columns legend {
    padding: 0 4px;
    font-size: 11px;
    opacity: 0.7;
}

.columns__list {
    margin: 0 0 6px;
    padding: 0;
    list-style: none;
}

.columns__row {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 4px;
}

.columns__row input {
    flex: 1;
    width: auto;
    padding: 3px 6px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 12px;
}

.columns__pick,
.columns__remove,
.columns__add,
.columns__presets button {
    padding: 3px 8px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}

.columns__pick {
    min-width: 26px;
}

.columns__pick.is-selected {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
}

.columns__add {
    width: 100%;
}

.columns__pick:disabled,
.columns__remove:disabled,
.columns__add:disabled,
.columns__presets button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.columns__presets {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 6px;
}
</style>
