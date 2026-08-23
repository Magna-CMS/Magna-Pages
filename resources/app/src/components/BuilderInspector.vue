<script setup lang="ts">
import { computed, ref } from 'vue'

import BuilderColumnControls from './BuilderColumnControls.vue'
import BuilderIconPicker from './BuilderIconPicker.vue'
import BuilderMediaPicker from './BuilderMediaPicker.vue'
import BuilderStyleControls from './BuilderStyleControls.vue'
import BuilderTagSuggest from './BuilderTagSuggest.vue'
import type { BuilderApi } from '../api'
import type { Located } from '../document/locate'
import type { Breakpoint } from '../document/responsive'
import { insertTag } from '../document/tags'
import {
    useTagCompletion,
    type TagCompletionState,
    type TextField,
} from '../document/useTagCompletion'
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
    /** What contains the selection, outermost first, ending with it. */
    ancestors?: { id: string; kind: string; label: string }[]
    /** The document's API — a media picker has to fetch and upload. */
    api: BuilderApi
    /** Row-layout controls, for a section: how its columns lay out. */
    rowControls?: StyleControl[]
    /**
     * Every show/hide rule this install can evaluate, as the server listed
     * them. Only the plugin ones get a control here — the two built-ins have
     * their own, above.
     */
    displayConditions?: DisplayConditionOption[]
}>()

/**
 * Whether the selection is switched off the site.
 *
 * Deliberately not named `hidden`: that collides with the native HTML
 * attribute of the same name, and the template resolves the DOM one
 * instead — silently, and only at type-check.
 */
/** Narrows a long settings list to what an editor is looking for. */
const settingSearch = ref('')

/**
 * The fields on the open tab, narrowed by the search.
 *
 * A field with no group belongs to Content, which is what every field was
 * before blocks could group anything.
 */
const visibleFields = computed(() => {
    const fields = (props.definition?.fields ?? []).filter(
        (field) => (field.group ?? 'content').toLowerCase() === tab.value,
    )

    const needle = settingSearch.value.trim().toLowerCase()
    if (needle === '') {
        return fields
    }

    return fields.filter(
        (field) =>
            field.label.toLowerCase().includes(needle) ||
            field.handle.toLowerCase().includes(needle),
    )
})

const switchedOff = computed(
    () =>
        ((props.located?.node as { settings?: Record<string, unknown> } | undefined)?.settings
            ?.hidden ?? false) === true,
)

const emit = defineEmits<{
    edit: [pointer: string, handle: string, value: unknown]
    editSetting: [pointer: string, key: string, value: unknown]
    addColumn: [sectionId: string]
    removeColumn: [sectionId: string, columnId: string]
    setSpans: [sectionId: string, spans: number[]]
    setStyle: [pointer: string, key: string, value: string]
    setRowStyle: [pointer: string, key: string, value: string]
    select: [nodeId: string]
    /** Switch the selection off the site, or back on. */
    setHidden: [hidden: boolean]
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
        /*
         * A block groups its own settings.
         *
         * Tabs used to be a fixed three, which meant a block with fifteen
         * fields had one long tab and no say in it. A field naming a group
         * gets its own tab, in the order the block declares them — the same
         * promise the schema-driven inspector already makes about what the
         * fields ARE, extended to how they are arranged.
         *
         * Absent means Content, so a block that says nothing looks exactly
         * as it did.
         */
        const declared: InspectTab[] = []
        for (const field of props.definition.fields) {
            const group = (field.group ?? 'content').toLowerCase()
            if (!declared.includes(group as InspectTab)) {
                declared.push(group as InspectTab)
            }
        }

        const groups: InspectTab[] = props.definition.fields.length > 0 ? declared : []
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
/**
 * The tabs the BUILDER owns, whatever a block says.
 *
 * Style and Advanced have panels of their own — the style descriptor
 * table and the visibility/condition controls — so a block may not claim
 * those names for its fields.
 */
const BUILDER_TABS: InspectTab[] = ['style', 'advanced']

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

/** The icon picker hands back a name, not an event. */
function onPick(field: BlockFieldDefinition, value: string) {
    if (props.located) {
        emit('edit', props.located.pointer, field.handle, value)
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
 * Where the caret was in each text field, by handle.
 *
 * Kept because choosing from the tag picker moves focus out of the field, so
 * by the time the insert runs the selection is gone. Recorded on the events
 * that can move a caret rather than on input alone — a click or an arrow key
 * moves it without changing a character.
 *
 * Not reactive: nothing renders from it, and making it reactive would redraw
 * the inspector on every keystroke for a value only ever read once.
 */
const caretByField = new Map<string, { at: number; to: number }>()

function rememberCaret(handle: string, event: Event): void {
    const element = event.target as HTMLTextAreaElement | HTMLInputElement | null

    if (element && typeof element.selectionStart === 'number') {
        caretByField.set(handle, {
            at: element.selectionStart,
            to: element.selectionEnd ?? element.selectionStart,
        })
    }
}

/**
 * Insert an inline tag token where the writer left the caret.
 *
 * A content-tier edit like any typing — the token resolves at render, and the
 * editor moves or deletes it as plain text.
 *
 * This appended before, which is only ever right when the caret happens to be
 * at the end: inserting a tag while editing the middle of a sentence put it
 * after the full stop, and the writer had to cut and paste it back. With no
 * remembered caret it still appends, which is the old behaviour and the safe
 * reading of "we do not know where they were".
 */
function insertInlineTag(field: BlockFieldDefinition, handle: string) {
    if (!props.located || handle === '') {
        return
    }

    const value = valueFor(field)
    const caret = caretByField.get(field.handle) ?? { at: value.length, to: value.length }

    const { value: next } = insertTag(value, handle, caret.at, caret.to)

    emit('edit', props.located.pointer, field.handle, next)
}

/**
 * The same tags, offered as you type them.
 *
 * The picker above is still there and still right for "what can I put here?";
 * this is for the writer who already knows, mid-sentence, and should not have
 * to leave the text to say so.
 */
const completion = useTagCompletion(
    () => tagSources.value,
    (handle, value) => {
        if (props.located) {
            emit('edit', props.located.pointer, handle, value)
        }
    },
)

function onTagTyping(field: BlockFieldDefinition, event: Event): void {
    rememberCaret(field.handle, event)

    if (!editable.value || Object.keys(tagSources.value).length === 0) {
        return
    }

    completion.refresh(field.handle, event.target as TextField)
}

/**
 * Keys the completion list owns while it is open.
 *
 * Stopped as well as prevented: Enter commits the field and Escape deselects
 * the node, and both are the wrong answer to "I am choosing from this list".
 */
function onTagKeydown(field: BlockFieldDefinition, event: KeyboardEvent): void {
    if (completion.handleKey(field.handle, event.target as TextField, event)) {
        event.preventDefault()
        event.stopPropagation()
    }
}

/** The open list, if it belongs to this field. */
function suggestFor(handle: string): TagCompletionState | null {
    const state = completion.state.value

    return state !== null && state.handle === handle ? state : null
}

/**
 * Chosen with the mouse.
 *
 * The element is fetched by id rather than held in a ref: the fields are
 * rendered by a `v-for` over the block's own schema, so a ref would be an
 * array whose order is the schema's, and the id is already there and already
 * unique per field.
 */
function acceptTag(handle: string, tag: string): void {
    const element = document.getElementById(`field-${handle}`)

    completion.accept(handle, element as TextField | null, tag)
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
        <!-- Nothing selected: the page's own settings live in their own
             panel now, so this says where rather than showing them. -->
        <p v-if="!located" class="inspector__empty">
            Select something on the page, or open <strong>Page</strong> for the
            background, header and footer.
        </p>

        <template v-else>
            <!--
                The element, and whether it is on.

                Off takes it off the site without deleting the work — the
                switch every builder has, and the reason "hide it for now"
                does not mean "rebuild it later". It stays VISIBLE in the
                canvas while off, because a node that vanished when you
                switched it off could never be switched back on.
            -->
            <div class="inspector__head">
                <!-- What contains this, so a section is reachable without
                     hunting for a sliver of it to click. -->
                <p v-if="(ancestors ?? []).length > 1" class="inspector__crumbs">
                    <template v-for="(step, index) in (ancestors ?? []).slice(0, -1)" :key="step.id">
                        <button type="button" @click="$emit('select', step.id)">{{ step.label }}</button>
                        <span v-if="index < (ancestors ?? []).length - 2" aria-hidden="true">›</span>
                    </template>
                </p>

                <div class="inspector__title">
                    <p class="inspector__block">{{ title }}</p>

                    <label class="inspector__onoff" :title="switchedOff ? 'Off: not on the site' : 'On'">
                        <input
                            type="checkbox"
                            :checked="!switchedOff"
                            :disabled="!capabilities.structure"
                            @change="$emit('setHidden', !($event.target as HTMLInputElement).checked)"
                        />
                        <span>{{ switchedOff ? 'Off' : 'On' }}</span>
                    </label>
                </div>
            </div>

            <div v-if="tabs.length > 1" class="inspector__tabs" role="tablist" aria-label="Settings group">
                <button
                    v-for="name in tabs"
                    :id="`inspector-tab-${name}`"
                    :key="name"
                    type="button"
                    role="tab"
                    class="inspector__tab"
                    :class="{ 'is-active': tab === name }"
                    :aria-selected="tab === name"
                    aria-controls="inspector-panel"
                    :tabindex="tab === name ? 0 : -1"
                    @click="ui.inspectTab = name"
                >
                    {{ name }}
                </button>
            </div>
        </template>

        <!-- The settings themselves, as the panel those tabs control. -->
        <div
            id="inspector-panel"
            :role="tabs.length > 1 ? 'tabpanel' : undefined"
            :aria-labelledby="tabs.length > 1 ? `inspector-tab-${tab}` : undefined"
        >

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

        <!--
            Any tab a BLOCK declared shows that block's fields. Keyed on
            "not one of the builder's own" rather than on the literal
            'content', which is what it was: tabs became schema-declared
            while the panel stayed pinned to one name, so every group a
            block invented drew an empty panel.
        -->
        <template v-else-if="located && definition && tab !== null && !BUILDER_TABS.includes(tab)">
            <!--
                Finding a setting among many. Shown only when there are
                enough to hunt through: a search box above four fields is
                furniture, not help.
            -->
            <label v-if="definition.fields.length > 6" class="inspector__search">
                <span class="inspector__label">Search settings</span>
                <input v-model="settingSearch" type="search" placeholder="Search settings…" />
            </label>

            <div v-for="field in visibleFields" :key="field.handle" class="inspector__field">
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
                        @blur="rememberCaret(field.handle, $event)"
                        @select="rememberCaret(field.handle, $event)"
                        @keyup="onTagTyping(field, $event)"
                        @input="onTagTyping(field, $event)"
                        @keydown="onTagKeydown(field, $event)"
                        @click="onTagTyping(field, $event)"
                    />

                    <!--
                        Drawn under the field it belongs to and nowhere else,
                        so two text fields cannot both claim the list.
                    -->
                    <BuilderTagSuggest
                        v-if="suggestFor(field.handle)"
                        :matches="suggestFor(field.handle)!.matches"
                        :active="suggestFor(field.handle)!.index"
                        :query="suggestFor(field.handle)!.open.query"
                        @pick="acceptTag(field.handle, $event)"
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

                <!--
                    An icon field used to render as a text input, which
                    asked an editor to type `core:chevron-right` from
                    memory — a control that only works for someone who
                    already knows the answer.
                -->
                <!--
                    A media field rendered as a text box before this, which
                    asked an editor to type a media id from memory — so a
                    logo had no way to get a picture at all.
                -->
                <BuilderMediaPicker
                    v-else-if="field.type === 'media'"
                    :id="`field-${field.handle}`"
                    :value="valueFor(field)"
                    :disabled="!editable"
                    :api="api"
                    @pick="onPick(field, $event)"
                />

                <BuilderIconPicker
                    v-else-if="field.type === 'icon'"
                    :id="`field-${field.handle}`"
                    :value="valueFor(field)"
                    :disabled="!editable"
                    @pick="onPick(field, $event)"
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
                        @blur="rememberCaret(field.handle, $event)"
                        @select="rememberCaret(field.handle, $event)"
                        @keyup="field.type === 'text' ? onTagTyping(field, $event) : rememberCaret(field.handle, $event)"
                        @input="field.type === 'text' ? onTagTyping(field, $event) : undefined"
                        @keydown="field.type === 'text' ? onTagKeydown(field, $event) : undefined"
                        @click="field.type === 'text' ? onTagTyping(field, $event) : rememberCaret(field.handle, $event)"
                    />

                    <BuilderTagSuggest
                        v-if="suggestFor(field.handle)"
                        :matches="suggestFor(field.handle)!.matches"
                        :active="suggestFor(field.handle)!.index"
                        :query="suggestFor(field.handle)!.open.query"
                        @pick="acceptTag(field.handle, $event)"
                    />

                    <!--
                        A tag in a heading or a button label.
                        BindingResolver substitutes {tag:…} in ANY string
                        value, so this already worked at render — the picker
                        was only ever drawn beside a textarea, which left the
                        one place people write short live text without a way
                        to insert one. Numbers are excluded: a token in a
                        number field is not a number.
                    -->
                    <select
                        v-if="editable && field.type === 'text' && Object.keys(tagSources).length > 0"
                        class="inspector__taginsert"
                        title="Insert a dynamic tag — resolves when the page renders"
                        :value="''"
                        @change="
                            insertInlineTag(field, ($event.target as HTMLSelectElement).value);
                            ($event.target as HTMLSelectElement).value = ''
                        "
                    >
                        <option value="" disabled selected>{ }</option>
                        <option v-for="(label, handle) in tagSources" :key="handle" :value="handle">
                            {{ label }}
                        </option>
                    </select>
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
    </div>
</template>

<style scoped>
.inspector__head {
    margin-bottom: 8px;
}

.inspector__crumbs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px;
    margin: 0 0 3px;
    font-size: 11px;
    opacity: 0.7;
}

.inspector__crumbs button {
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 11px;
    text-transform: capitalize;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}

.inspector__title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.inspector__onoff {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    cursor: pointer;
}

.inspector__onoff input:disabled {
    cursor: not-allowed;
}

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
    /* Without this an input in a flex row refuses to shrink past its
       intrinsic width — and the pickers beside it, sized by their widest
       option, squeeze it to nothing. */
    min-width: 0;
}

/*
 * The tag picker is used in two layouts: a block under a textarea, and a
 * narrow control inside a bind row. Sized for the first, it ate the row in
 * the second and left the text field a sliver — which is what made a
 * heading look like it had no editable text at all.
 */
.inspector__bindrow > .inspector__taginsert {
    width: 40px;
    flex: 0 0 auto;
    margin-top: 0;
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

/*
 * The completions for a `{tag:` being typed.
 *
 * In flow rather than absolutely positioned: the inspector is a scrolling
 * column, and a floating list would need its own scroll and resize handling
 * to stay attached to a field that moves under it. Pushing the fields below
 * it down for a moment is the cheaper honesty.
 */
.inspector__tagsuggest {
    margin: 4px 0 0;
    padding: 2px;
    list-style: none;
    border: 1px solid var(--builder-border, rgba(255, 255, 255, 0.14));
    border-radius: 6px;
    background: var(--builder-panel, rgba(20, 22, 30, 0.96));
    max-height: 180px;
    overflow-y: auto;
}

.inspector__tagsuggest li {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 4px 6px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.inspector__tagsuggest--active,
.inspector__tagsuggest li:hover {
    background: var(--builder-accent-soft, rgba(120, 160, 255, 0.18));
}

.inspector__tagsuggest-handle {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.inspector__tagsuggest-label {
    opacity: 0.65;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
