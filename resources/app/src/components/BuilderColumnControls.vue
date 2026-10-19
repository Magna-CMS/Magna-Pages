<script setup lang="ts">
import { computed } from 'vue'

import {
    COLUMN_PRESETS,
    COLUMN_TEMPLATE,
    declaredMobileStack,
    parseTracks,
} from '../document/edits'
import { effectiveAt } from '../document/responsive'
import type { SectionNode } from '../document/types'

/**
 * Row layout: how many columns this section has and how wide each one is.
 *
 * TWO WIDTH SCALES, and only ever one of them on screen.
 *
 * Twelfths (`span`) is the original and still the default: integers over a
 * 12-column grid, rebalanced back to twelve by the producers so a row can
 * never be saved half-empty or overflowing however the numbers are typed.
 *
 * Exact widths (`settings.row.columnTemplate`) is what twelfths cannot say.
 * A ratio like 1.15 / 0.85 has no twelfth, so a design that wanted one had
 * to put it in theme CSS keyed off a section class — and then these inputs
 * showed `6, 6` for a band the page rendered unequal, with nothing on
 * screen admitting the override. When the row carries a template these
 * inputs edit THAT, so the number here is the width out there.
 *
 * The inputs edit the BASE value whatever device is previewed, exactly as
 * the twelfths they replace always have. The one width decision that is
 * genuinely per-device gets the one control that is: a grid row does not
 * inherit the shell's flex collapse, so "Stack on mobile" writes the
 * narrow-width value into the document rather than leaving it to a default
 * the builder could not show.
 */

const props = defineProps<{
    section: SectionNode
    /** Highlighted when the editor is inspecting one column of the row. */
    selectedColumn: string | null
    canEdit: boolean
}>()

const emit = defineEmits<{
    addColumn: []
    removeColumn: [columnId: string]
    setSpans: [spans: number[]]
    /** Exact widths as grid tracks, or null to go back to twelfths. */
    setColumnTemplate: [payload: { tracks: number[] | null; stackOnMobile: boolean }]
    select: [nodeId: string]
}>()

const columns = computed(() => props.section.columns ?? [])

const spans = computed(() => columns.value.map((column) => column.span))

/** The row's layout set, which is where an exact-width template lives. */
const row = computed<Record<string, unknown>>(() => {
    const stored = props.section.settings?.row

    return typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
})

/**
 * The stored widths, but only when they describe THIS row.
 *
 * A template with the wrong number of tracks is not shown as widths: the
 * producers keep it to one track per column, so a mismatch means a
 * hand-written document, and guessing which column a track belongs to is
 * the kind of invention this control exists to stop.
 */
const tracks = computed<number[] | null>(() => {
    const template = effectiveAt(row.value[COLUMN_TEMPLATE], 'base')
    if (template === '') {
        return null
    }

    const parsed = parseTracks(template)

    return parsed !== null && parsed.length === columns.value.length ? parsed : null
})

const exact = computed(() => tracks.value !== null)

/**
 * A stored template this panel cannot show — one hand-written, or written
 * for a different number of columns.
 *
 * Said out loud rather than ignored. The whole point of the key is that
 * the widths on screen are the widths on the page; a template quietly
 * laying the row out while these inputs show twelfths would be the
 * original bug wearing a new coat.
 */
const unreadable = computed(() => {
    const template = effectiveAt(row.value[COLUMN_TEMPLATE], 'base')

    return template !== '' && tracks.value === null ? template : ''
})

const stacked = computed(() => declaredMobileStack(row.value))

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

/**
 * Switch to exact widths without changing the page.
 *
 * The seed is the spans themselves — `6fr 6fr` lays out exactly as
 * `flex: 6 6 0%` did — so turning this on moves the ratio into the
 * document and renders the same picture, which is what makes it safe to
 * offer on a page already built.
 */
function useExactWidths() {
    emit('setColumnTemplate', { tracks: [...spans.value], stackOnMobile: true })
}

function useTwelfths() {
    emit('setColumnTemplate', { tracks: null, stackOnMobile: false })
}

function setTrack(index: number, value: number) {
    if (tracks.value === null || !(value > 0)) {
        return
    }

    const next = [...tracks.value]
    next[index] = value

    emit('setColumnTemplate', { tracks: next, stackOnMobile: stacked.value })
}

function setStacking(on: boolean) {
    emit('setColumnTemplate', { tracks: tracks.value, stackOnMobile: on })
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

                <!-- The width the page actually renders, when the row
                     stores one. Editing it writes the track list AND the
                     twelfths behind it, so neither can drift from the
                     other. -->
                <input
                    v-if="tracks"
                    type="number"
                    min="0.05"
                    step="0.05"
                    :value="tracks[index]"
                    :disabled="!canEdit"
                    :aria-label="`Column ${index + 1} width in fr`"
                    title="The width this column renders at"
                    @change="setTrack(index, Number(($event.target as HTMLInputElement).value))"
                />

                <input
                    v-else
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

        <p v-if="unreadable" class="columns__note">
            This row lays its columns out as <code>{{ unreadable }}</code>, which these
            fields cannot show. Use exact widths to replace it.
        </p>

        <div class="columns__mode">
            <button
                type="button"
                :disabled="!canEdit"
                :title="
                    exact
                        ? 'Go back to widths in twelfths'
                        : 'Edit the width each column renders at, instead of its share of twelve'
                "
                @click="exact ? useTwelfths() : useExactWidths()"
            >
                {{ exact ? 'Use twelfths' : 'Use exact widths' }}
            </button>

            <!-- A grid row does not inherit the one-column collapse the
                 shell gives a flex row, so this is a value in the document
                 rather than something the renderer assumes. -->
            <label v-if="exact" class="columns__stack">
                <input
                    type="checkbox"
                    :checked="stacked"
                    :disabled="!canEdit"
                    @change="setStacking(($event.target as HTMLInputElement).checked)"
                />
                Stack on mobile
            </label>
        </div>

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
.columns__mode button,
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

.columns__mode {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
}

.columns__note {
    margin: 6px 0 0;
    font-size: 11px;
    line-height: 1.4;
    opacity: 0.75;
}

.columns__stack {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    opacity: 0.8;
}

.columns__pick:disabled,
.columns__remove:disabled,
.columns__add:disabled,
.columns__mode button:disabled,
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
