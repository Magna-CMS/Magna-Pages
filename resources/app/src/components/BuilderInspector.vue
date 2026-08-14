<script setup lang="ts">
import { computed } from 'vue'

import BuilderColumnControls from './BuilderColumnControls.vue'
import BuilderStyleControls from './BuilderStyleControls.vue'
import type { Located } from '../document/locate'
import type { Breakpoint } from '../document/responsive'
import type {
    BlockDefinition,
    BlockFieldDefinition,
    Capabilities,
    DisplayConditionOption,
    SectionNode,
    StyleControl,
} from '../document/types'
import { useUiStore, type InspectTab } from '../stores/ui'

/**
 * The inspector, driven entirely by the block's own field schema.
 *
 * Nothing here is hardcoded per block type: a third-party block that ships a
 * block.json gets a working editor with no builder change, which is the same
 * promise the canvas makes by rendering through the production views.
 *
 * Settings are grouped Content / Style / Advanced, and a group with nothing
 * in it does not get a tab: an empty tab teaches the editor that tabs are
 * usually empty, and then they stop opening the full one too.
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
    /** The row the selection belongs to — the section itself, or a column's parent. */
    layoutSection?: SectionNode | null
    /** The style controls this node kind may use, as the server describes them. */
    styleControls?: StyleControl[]
    /** The device being previewed; style edits land on this breakpoint. */
    styleBreakpoint: Breakpoint
    /** Row-layout controls, for a section: how its columns lay out. */
    rowControls?: StyleControl[]
    /**
     * Every show/hide rule this install can evaluate, as the server listed
     * them. Only the plugin ones get a control here — the two built-ins have
     * their own, above.
     */
    displayConditions?: DisplayConditionOption[]
}>()

const emit = defineEmits<{
    edit: [pointer: string, handle: string, value: unknown]
    editSetting: [pointer: string, key: string, value: unknown]
    addColumn: [sectionId: string]
    removeColumn: [sectionId: string, columnId: string]
    setSpans: [sectionId: string, spans: number[]]
    setStyle: [pointer: string, key: string, value: string]
    setRowStyle: [pointer: string, key: string, value: string]
    select: [nodeId: string]
}>()

function onStyleSet(key: string, value: string) {
    if (props.located) {
        emit('setStyle', props.located.pointer, key, value)
    }
}

function onRowStyleSet(key: string, value: string) {
    if (props.located) {
        emit('setRowStyle', props.located.pointer, key, value)
    }
}

/** The row-layout set on a selected section (settings.row). */
const rowValues = computed<Record<string, unknown>>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const stored = settings?.row

    return typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
})

/** The selected node's stored style set, whatever it currently holds. */
const styleValues = computed<Record<string, unknown>>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const stored = settings?.style

    return typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
})

const ui = useUiStore()

/** Which groups this selection actually has something to show. */
const tabs = computed<InspectTab[]>(() => {
    if (!props.located) {
        return []
    }

    if (props.definition) {
        const groups: InspectTab[] = props.definition.fields.length > 0 ? ['content'] : []
        // A block styles itself through the same table sections use; the
        // renderer decides which keys it offers.
        if ((props.styleControls ?? []).length > 0) {
            groups.push('style')
        }

        return groups
    }
    if (props.located.kind === 'section') {
        return ['style', 'advanced']
    }

    // A column's only settings are its row's layout, which it can edit.
    return props.located.kind === 'column' && props.layoutSection ? ['style'] : []
})

/**
 * The tab actually rendered. The store keeps the editor's last choice, but
 * a selection that has no such group must not render a blank panel.
 */
const tab = computed<InspectTab | null>(() =>
    tabs.value.includes(ui.inspectTab) ? ui.inspectTab : (tabs.value[0] ?? null),
)

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

/* ------------------------------------------------- plugin display conditions */

/** The rules a plugin contributed, which is everything but the two built-ins. */
const pluginConditions = computed<DisplayConditionOption[]>(() =>
    (props.displayConditions ?? []).filter((option) => !option.builtIn),
)

function conditionIsOn(handle: string): boolean {
    return conditions.value.some((rule) => rule.type === handle)
}

/**
 * Turns one plugin condition on or off.
 *
 * Adding writes `{type}` and nothing else; removing drops that rule. An
 * existing rule is never rewritten, because a plugin condition may carry
 * settings this inspector cannot render — rewriting it to `{type}` would
 * silently discard them. Every other rule, modelled or not, is left exactly
 * as it was, which is the same promise writeConditions() makes.
 */
function togglePluginCondition(handle: string, on: boolean): void {
    if (!props.located) {
        return
    }

    const rules = conditions.value.filter((rule) => rule.type !== handle)

    if (on) {
        rules.push({ type: handle })
    }

    emit('editSetting', props.located.pointer, 'conditions', rules)
}

/** Motion preset on the selected section (settings.motion, CSS-only). */
const motion = computed<string>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
    const stored = settings?.motion

    return stored === 'fade' || stored === 'rise' ? stored : ''
})

function writeMotion(preset: string) {
    if (props.located) {
        emit('editSetting', props.located.pointer, 'motion', preset === '' ? null : preset)
    }
}

/** Custom declaration list on the selected section (settings.customCss). */
const customCss = computed<string>(() => {
    const settings = (props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings

    return typeof settings?.customCss === 'string' ? settings.customCss : ''
})

function writeCustomCss(value: string) {
    if (props.located) {
        emit('editSetting', props.located.pointer, 'customCss', value.trim() === '' ? null : value)
    }
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

/** Dynamic tags, offered as inline {tag:…} inserts inside text values. */
const tagSources = computed<Record<string, string>>(() => {
    const tags: Record<string, string> = {}
    for (const [source, label] of Object.entries(props.bindingSources)) {
        if (source.startsWith('tag.')) {
            tags[source.slice(4)] = label
        }
    }

    return tags
})

/**
 * Append an inline tag token to the field's current text. A content-tier
 * edit like any typing — the token resolves at render, and the editor can
 * move or delete it as plain text.
 */
function insertInlineTag(field: BlockFieldDefinition, handle: string) {
    if (!props.located || handle === '') {
        return
    }

    emit('edit', props.located.pointer, field.handle, valueFor(field) + `{tag:${handle}}`)
}

/** The selected column's id, when a column is what is selected. */
const selectedColumnId = computed<string | null>(() =>
    props.located?.kind === 'column'
        ? String((props.located.node as { id?: string }).id ?? '')
        : null,
)

/** What the selection is called at the top of the panel. */
const title = computed<string>(() => {
    if (!props.located) {
        return ''
    }
    if (props.definition) {
        return props.definition.label
    }
    if (props.located.kind === 'column') {
        return `Column (${String((props.located.node as { span?: number }).span ?? 12)})`
    }

    return props.located.kind === 'section' ? 'Section' : 'Block'
})
</script>

<template>
    <div class="inspector">
        <p v-if="!located" class="inspector__empty">
            Select something on the page, or use Add to place a new element.
        </p>

        <template v-else>
            <p class="inspector__block">{{ title }}</p>

            <div v-if="tabs.length > 1" class="inspector__tabs" role="tablist" aria-label="Settings group">
                <button
                    v-for="name in tabs"
                    :key="name"
                    type="button"
                    role="tab"
                    class="inspector__tab"
                    :class="{ 'is-active': tab === name }"
                    :aria-selected="tab === name"
                    @click="ui.inspectTab = name"
                >
                    {{ name }}
                </button>
            </div>
        </template>

        <template v-if="located && definition && tab === 'style'">
            <BuilderStyleControls
                v-if="styleControls && styleControls.length > 0"
                :controls="styleControls"
                :style="styleValues"
                :can-edit="capabilities.style"
                :breakpoint="styleBreakpoint"
                @set="onStyleSet"
            />

            <p v-if="!capabilities.style" class="inspector__locked">
                Styling needs the design permission.
            </p>
        </template>

        <template v-else-if="located && definition && tab === 'content'">
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

                <template v-else-if="field.type === 'textarea' || field.type === 'richtext'">
                    <textarea
                        :id="`field-${field.handle}`"
                        rows="4"
                        :value="valueFor(field)"
                        :disabled="!editable"
                        @change="onInput(field, $event)"
                    />
                    <select
                        v-if="editable && Object.keys(tagSources).length > 0"
                        class="inspector__taginsert"
                        title="Insert a dynamic tag — resolves when the page renders"
                        :value="''"
                        @change="
                            insertInlineTag(field, ($event.target as HTMLSelectElement).value);
                            ($event.target as HTMLSelectElement).value = ''
                        "
                    >
                        <option value="" disabled selected>Insert dynamic tag…</option>
                        <option v-for="(label, handle) in tagSources" :key="handle" :value="handle">
                            {{ label }}
                        </option>
                    </select>
                </template>

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

        <template v-else-if="located && located.kind === 'column' && tab === 'style' && layoutSection">
            <BuilderColumnControls
                :section="layoutSection"
                :selected-column="selectedColumnId"
                :can-edit="capabilities.structure"
                @add-column="$emit('addColumn', layoutSection.id)"
                @remove-column="$emit('removeColumn', layoutSection.id, $event)"
                @set-spans="$emit('setSpans', layoutSection.id, $event)"
                @select="$emit('select', $event)"
            />

            <BuilderStyleControls
                v-if="styleControls && styleControls.length > 0"
                :controls="styleControls"
                :style="styleValues"
                :can-edit="capabilities.style"
                :breakpoint="styleBreakpoint"
                @set="onStyleSet"
            />

            <p v-if="!capabilities.structure" class="inspector__locked">
                Changing the row layout needs the layout permission.
            </p>
        </template>

        <template v-else-if="located && located.kind === 'section' && tab === 'style'">
            <BuilderColumnControls
                v-if="layoutSection"
                :section="layoutSection"
                :selected-column="null"
                :can-edit="capabilities.structure"
                @add-column="$emit('addColumn', layoutSection.id)"
                @remove-column="$emit('removeColumn', layoutSection.id, $event)"
                @set-spans="$emit('setSpans', layoutSection.id, $event)"
                @select="$emit('select', $event)"
            />

            <BuilderStyleControls
                v-if="rowControls && rowControls.length > 0"
                :controls="rowControls"
                :style="rowValues"
                :can-edit="capabilities.style"
                :breakpoint="styleBreakpoint"
                @set="onRowStyleSet"
            />

            <BuilderStyleControls
                v-if="styleControls && styleControls.length > 0"
                :controls="styleControls"
                :style="styleValues"
                :can-edit="capabilities.style"
                :breakpoint="styleBreakpoint"
                @set="onStyleSet"
            />

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

            <label class="inspector__field inspector__stack">
                Motion
                <select
                    :value="motion"
                    :disabled="!capabilities.style"
                    @change="writeMotion(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">None</option>
                    <option value="fade">Fade in</option>
                    <option value="rise">Rise in</option>
                </select>
            </label>

            <p v-if="!capabilities.style" class="inspector__locked">
                Visibility and motion need the design permission.
            </p>
        </template>

        <template v-else-if="located && located.kind === 'section' && tab === 'advanced'">
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

                <!--
                    Rules a plugin contributed. Listed only when there are
                    any, so an install with none sees no empty heading — and
                    only ever the ones the server says it can evaluate, since
                    a type it refuses hides the node rather than erroring.
                -->
                <template v-if="pluginConditions.length > 0">
                    <p class="inspector__hint">Also show only when</p>

                    <label
                        v-for="option in pluginConditions"
                        :key="option.handle"
                        class="inspector__check"
                    >
                        <input
                            type="checkbox"
                            :checked="conditionIsOn(option.handle)"
                            :disabled="!capabilities.style"
                            @change="
                                togglePluginCondition(
                                    option.handle,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ option.label }}
                    </label>
                </template>
            </fieldset>

            <label class="inspector__field inspector__stack">
                Custom CSS
                <textarea
                    rows="3"
                    placeholder="margin-top: 2rem; letter-spacing: 0.1em"
                    :value="customCss"
                    :disabled="!capabilities.style"
                    @change="writeCustomCss(($event.target as HTMLTextAreaElement).value)"
                />
                <span class="inspector__hint">Declarations only — they apply to this section's own element. Functions other than var(--token) are dropped at render.</span>
            </label>

            <p v-if="!capabilities.style" class="inspector__locked">
                Conditions and custom CSS need the design permission.
            </p>
        </template>

        <p v-else-if="located && tabs.length === 0" class="inspector__empty">
            {{ located.kind === 'block' ? 'This block is not installed.' : 'No settings yet.' }}
        </p>
    </div>
</template>

<style scoped>
.inspector__block {
    margin: 0 0 8px;
    font-weight: 600;
}

.inspector__tabs {
    display: flex;
    gap: 3px;
    margin-bottom: 12px;
}

.inspector__tab {
    flex: 1;
    padding: 4px 6px;
    border: 1px solid var(--builder-border);
    border-radius: 999px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-transform: capitalize;
    cursor: pointer;
}

.inspector__tab.is-active {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
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

.inspector__taginsert {
    margin-top: 4px;
    font-size: 12px;
    opacity: 0.85;
}

.inspector__hint {
    font-size: 11px;
    opacity: 0.6;
    line-height: 1.4;
}

.inspector__empty,
.inspector__locked,
.inspector__bound {
    font-size: 12px;
    opacity: 0.7;
}
</style>
