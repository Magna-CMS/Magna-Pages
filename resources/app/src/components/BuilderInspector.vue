<script setup lang="ts">
import { computed } from 'vue'

import type { Located } from '../document/locate'
import type { BlockDefinition, BlockFieldDefinition, Capabilities } from '../document/types'

/**
 * The inspector, driven entirely by the block's own field schema.
 *
 * Nothing here is hardcoded per block type: a third-party block that ships a
 * block.json gets a working editor with no builder change, which is the same
 * promise the canvas makes by rendering through the production views.
 *
 * Controls disable when the actor lacks the capability. That is a courtesy —
 * the server refuses the patch regardless (PatchAuthorizer) — but a UI that
 * offers an action it knows will fail is a bad UI.
 */

const props = defineProps<{
    located: Located | null
    definition?: BlockDefinition | undefined
    capabilities: Capabilities
    bindingSources: Record<string, string>
}>()

const emit = defineEmits<{
    edit: [pointer: string, handle: string, value: unknown]
    editSetting: [pointer: string, key: string, value: unknown]
}>()

/** Per-device visibility of the selected section (absent key = visible). */
const visibility = computed<Record<string, boolean>>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const stored = settings?.visibility

    const map = typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}

    return {
        desktop: map.desktop !== false,
        tablet: map.tablet !== false,
        mobile: map.mobile !== false,
    }
})

function toggleDevice(device: string) {
    if (!props.located) {
        return
    }

    emit('editSetting', props.located.pointer, 'visibility', {
        ...visibility.value,
        [device]: !visibility.value[device],
    })
}

/**
 * Display conditions, read back from the node. The v1 UI manages one
 * audience rule and one schedule window — the document format holds any
 * list, and rules this UI does not model are PRESERVED on write, not
 * dropped: an install with plugin-contributed conditions must not lose
 * them because this inspector predates them.
 */
interface ConditionRule {
    type: string
    [key: string]: unknown
}

const conditions = computed<ConditionRule[]>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const stored = settings?.conditions

    return Array.isArray(stored) ? (stored.filter((c) => typeof c === 'object' && c !== null) as ConditionRule[]) : []
})

const audience = computed<string>(() => {
    const rule = conditions.value.find((c) => c.type === 'auth')

    return typeof rule?.show === 'string' ? rule.show : 'everyone'
})

const schedule = computed<{ from: string; until: string }>(() => {
    const rule = conditions.value.find((c) => c.type === 'schedule')

    return {
        from: typeof rule?.from === 'string' ? rule.from : '',
        until: typeof rule?.until === 'string' ? rule.until : '',
    }
})

function writeConditions(next: { audience?: string; from?: string; until?: string }) {
    if (!props.located) {
        return
    }

    const audienceValue = next.audience ?? audience.value
    const fromValue = next.from ?? schedule.value.from
    const untilValue = next.until ?? schedule.value.until

    // Foreign rule types survive untouched at the front of the list.
    const rules: ConditionRule[] = conditions.value.filter(
        (c) => c.type !== 'auth' && c.type !== 'schedule',
    )

    if (audienceValue === 'guests' || audienceValue === 'authenticated') {
        rules.push({ type: 'auth', show: audienceValue })
    }
    if (fromValue !== '' || untilValue !== '') {
        const rule: ConditionRule = { type: 'schedule' }
        if (fromValue !== '') {
            rule.from = fromValue
        }
        if (untilValue !== '') {
            rule.until = untilValue
        }
        rules.push(rule)
    }

    emit('editSetting', props.located.pointer, 'conditions', rules)
}

const data = computed<Record<string, unknown>>(() => {
    const node = props.located?.node as { data?: Record<string, unknown> } | undefined

    return node?.data ?? {}
})

const editable = computed(() => props.capabilities.content)

function onInput(field: BlockFieldDefinition, event: Event) {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement

    if (props.located) {
        emit('edit', props.located.pointer, field.handle, target.value)
    }
}

function valueFor(field: BlockFieldDefinition): string {
    const value = data.value[field.handle]

    return typeof value === 'string' || typeof value === 'number' ? String(value) : ''
}

function isBound(field: BlockFieldDefinition): boolean {
    const value = data.value[field.handle]

    return typeof value === 'object' && value !== null && '$bind' in value
}

function boundSource(field: BlockFieldDefinition): string {
    const value = data.value[field.handle]

    return isBound(field) ? String((value as Record<string, unknown>).$bind ?? '') : ''
}

/** Binding writes are design-tier — the server refuses them below that. */
function bindField(field: BlockFieldDefinition, source: string) {
    if (!props.located) {
        return
    }

    emit(
        'edit',
        props.located.pointer,
        field.handle,
        source === '' ? '' : { $bind: source },
    )
}
</script>

<template>
    <div class="inspector">
        <h2 class="inspector__heading">Inspector</h2>

        <p v-if="!located" class="inspector__empty">Select something on the page.</p>

        <template v-else-if="definition">
            <p class="inspector__block">{{ definition.label }}</p>

            <div v-for="field in definition.fields" :key="field.handle" class="inspector__field">
                <label :for="`field-${field.handle}`">
                    {{ field.label }}
                    <span v-if="field.required" aria-hidden="true">*</span>
                </label>

                <div v-if="isBound(field)" class="inspector__bindrow">
                    <select
                        :value="boundSource(field)"
                        :disabled="!capabilities.style"
                        @change="bindField(field, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">— unbind (back to literal) —</option>
                        <option v-for="(label, source) in bindingSources" :key="source" :value="source">
                            {{ label }}
                        </option>
                    </select>
                </div>

                <textarea
                    v-else-if="field.type === 'textarea' || field.type === 'richtext'"
                    :id="`field-${field.handle}`"
                    rows="4"
                    :value="valueFor(field)"
                    :disabled="!editable"
                    @change="onInput(field, $event)"
                />

                <select
                    v-else-if="field.type === 'select' || field.type === 'alignment'"
                    :id="`field-${field.handle}`"
                    :value="valueFor(field)"
                    :disabled="!editable"
                    @change="onInput(field, $event)"
                >
                    <option v-for="(label, value) in field.options" :key="value" :value="value">
                        {{ label }}
                    </option>
                </select>

                <div v-else class="inspector__bindrow">
                    <input
                        :id="`field-${field.handle}`"
                        :type="field.type === 'number' ? 'number' : 'text'"
                        :value="valueFor(field)"
                        :disabled="!editable"
                        @change="onInput(field, $event)"
                    />
                    <!-- Bind to dynamic data: design-tier, so the toggle
                         only shows to actors the server would not refuse. -->
                    <select
                        v-if="capabilities.style && (field.type === 'text' || field.type === 'textarea')"
                        class="inspector__bindpick"
                        title="Bind to dynamic data"
                        :value="''"
                        @change="bindField(field, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="" disabled selected>⛓</option>
                        <option v-for="(label, source) in bindingSources" :key="source" :value="source">
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>

            <p v-if="!editable" class="inspector__locked">
                You have read-only access to this page's content.
            </p>
        </template>

        <template v-else-if="located.kind === 'section'">
            <p class="inspector__block">Section</p>

            <fieldset class="inspector__field inspector__devices">
                <legend>Show on</legend>
                <label v-for="device in ['desktop', 'tablet', 'mobile']" :key="device">
                    <input
                        type="checkbox"
                        :checked="visibility[device]"
                        :disabled="!capabilities.style"
                        @change="toggleDevice(device)"
                    />
                    {{ device }}
                </label>
            </fieldset>

            <fieldset class="inspector__field inspector__devices">
                <legend>Show when</legend>

                <label class="inspector__stack">
                    Audience
                    <select
                        :value="audience"
                        :disabled="!capabilities.style"
                        @change="writeConditions({ audience: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="everyone">Everyone</option>
                        <option value="guests">Guests only</option>
                        <option value="authenticated">Signed-in only</option>
                    </select>
                </label>

                <label class="inspector__stack">
                    From
                    <input
                        type="datetime-local"
                        :value="schedule.from"
                        :disabled="!capabilities.style"
                        @change="writeConditions({ from: ($event.target as HTMLInputElement).value })"
                    />
                </label>

                <label class="inspector__stack">
                    Until
                    <input
                        type="datetime-local"
                        :value="schedule.until"
                        :disabled="!capabilities.style"
                        @change="writeConditions({ until: ($event.target as HTMLInputElement).value })"
                    />
                </label>
            </fieldset>

            <p v-if="!capabilities.style" class="inspector__locked">
                Visibility and conditions need the design permission.
            </p>
        </template>

        <p v-else class="inspector__empty">
            {{ located.kind === 'block' ? 'This block is not installed.' : 'No settings yet.' }}
        </p>
    </div>
</template>

<style scoped>
.inspector__heading {
    margin: 0 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.inspector__block {
    margin: 0 0 12px;
    font-weight: 600;
}

.inspector__field {
    margin-bottom: 12px;
}

label {
    display: block;
    margin-bottom: 4px;
    font-size: 12px;
    opacity: 0.8;
}

input,
textarea,
select {
    width: 100%;
    padding: 5px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
}

input:disabled,
textarea:disabled,
select:disabled {
    opacity: 0.5;
}

.inspector__devices {
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    padding: 8px 10px;
}

.inspector__devices legend {
    font-size: 11px;
    opacity: 0.7;
    padding: 0 4px;
}

.inspector__devices label {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 4px 0;
    font-size: 13px;
    text-transform: capitalize;
}

.inspector__devices input {
    width: auto;
}

.inspector__stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin: 6px 0;
    font-size: 12px;
    opacity: 0.85;
    text-transform: none;
}

.inspector__stack select,
.inspector__stack input {
    width: 100%;
    padding: 5px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 12px;
}

.inspector__bindrow {
    display: flex;
    gap: 4px;
}

.inspector__bindrow input,
.inspector__bindrow > select:first-child {
    flex: 1;
}

.inspector__bindpick {
    width: 40px !important;
    flex: 0 0 auto;
}

.inspector__empty,
.inspector__locked,
.inspector__bound {
    font-size: 12px;
    opacity: 0.7;
}
</style>
