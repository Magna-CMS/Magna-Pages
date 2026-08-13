<script setup lang="ts">
import { computed } from 'vue'

import { COLUMN_PRESETS } from '../document/edits'
import { needsImportFlow, type DragSource } from '../document/placement'
import type { BlockDefinition, Capabilities } from '../document/types'
import type {
    LibraryAssetSummary,
    LibraryCollectionSummary,
    PatternSummary,
} from '../stores/document'
import { useUiStore } from '../stores/ui'

/**
 * Library mode: everything that can be added to the page, in three tabs —
 * the blocks this install has, the patterns this site saved, and the cloud
 * assets it can pull down.
 *
 * The structure picker sits at the top of Elements because it is the first
 * step of the workflow: choose a row shape, then fill its columns. A block
 * whose permission the actor lacks is shown disabled with the reason rather
 * than hidden — an editor who cannot find the HTML block assumes it is
 * missing and files a bug; one who sees it greyed out learns what to ask
 * their admin for.
 */

const props = defineProps<{
    registry: BlockDefinition[]
    targetColumn: string | null
    capabilities: Capabilities
    patterns: PatternSummary[]
    libraryAssets: LibraryAssetSummary[]
    libraryCollections: LibraryCollectionSummary[]
}>()

defineEmits<{
    add: [handle: string]
    addSection: [spans: number[]]
    insertPattern: [id: string]
    insertLibrary: [slug: string]
    dragStart: [source: DragSource, event: PointerEvent]
}>()

const ui = useUiStore()

const presets = COLUMN_PRESETS

function matches(...haystack: (string | null | undefined)[]): boolean {
    const needle = ui.search.trim().toLowerCase()
    if (needle === '') {
        return true
    }

    return haystack.some((entry) => (entry ?? '').toLowerCase().includes(needle))
}

/** Blocks grouped by the category their definition declares. */
const categories = computed(() => {
    const groups = new Map<string, BlockDefinition[]>()

    for (const definition of props.registry) {
        if (!matches(definition.label, definition.handle, definition.category)) {
            continue
        }
        const list = groups.get(definition.category) ?? []
        list.push(definition)
        groups.set(definition.category, list)
    }

    return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))
})

const visiblePatterns = computed(() =>
    props.patterns.filter((pattern) => matches(pattern.name, pattern.kind)),
)

const visibleAssets = computed(() =>
    props.libraryAssets.filter((asset) => matches(asset.name, asset.description, asset.kind)),
)

const canPlace = computed(() => props.capabilities.structure && props.targetColumn !== null)

function blocked(definition: BlockDefinition): string | null {
    if (!props.capabilities.structure) {
        return 'Needs layout permission'
    }
    if (definition.requiresPermission && !props.capabilities.style) {
        return `Needs ${definition.requiresPermission}`
    }
    if (props.targetColumn === null) {
        return 'Pick a column first'
    }

    return null
}

/** A preset drawn as proportional bars, so the shape reads before the label. */
function barStyle(span: number) {
    return { flex: `${span} 1 0%` }
}

/**
 * A card is draggable when the actor may change the structure at all —
 * where it is allowed to LAND is the placement table's business, not this
 * component's, so a new asset kind needs no change here.
 */
function draggable(source: DragSource): boolean {
    return props.capabilities.structure && !needsImportFlow(source)
}
</script>

<template>
    <div class="library">
        <label class="library__search">
            <span class="library__label">Search</span>
            <input
                type="search"
                :value="ui.search"
                placeholder="Search elements, patterns, cloud…"
                @input="ui.search = ($event.target as HTMLInputElement).value"
            />
        </label>

        <div class="library__tabs" role="tablist" aria-label="Library">
            <button
                v-for="tab in (['elements', 'patterns', 'cloud'] as const)"
                :key="tab"
                type="button"
                role="tab"
                class="library__tab"
                :class="{ 'is-active': ui.libraryTab === tab }"
                :aria-selected="ui.libraryTab === tab"
                @click="ui.libraryTab = tab"
            >
                {{ tab }}
            </button>
        </div>

        <template v-if="ui.libraryTab === 'elements'">
            <section class="library__group">
                <h3 class="library__heading">Add section</h3>
                <p class="library__hint">Pick a column structure to start a row.</p>

                <div class="library__structures">
                    <button
                        v-for="preset in presets"
                        :key="preset.label"
                        type="button"
                        class="library__structure"
                        :disabled="!capabilities.structure"
                        :aria-label="`Add section: ${preset.label}`"
                        :title="preset.label"
                        @click="$emit('addSection', preset.spans)"
                    >
                        <span class="library__bars" aria-hidden="true">
                            <span
                                v-for="(span, index) in preset.spans"
                                :key="index"
                                class="library__bar"
                                :style="barStyle(span)"
                            />
                        </span>
                        <small>{{ preset.label }}</small>
                    </button>
                </div>
            </section>

            <p v-if="!canPlace && capabilities.structure" class="library__hint">
                Select a column (or a section) to place an element in.
            </p>

            <section v-for="[category, blocks] in categories" :key="category" class="library__group">
                <button
                    type="button"
                    class="library__category"
                    :aria-expanded="ui.categoryOpen(category)"
                    @click="ui.toggleCategory(category)"
                >
                    <span aria-hidden="true">{{ ui.categoryOpen(category) ? '▾' : '▸' }}</span>
                    {{ category }}
                    <small>{{ blocks.length }}</small>
                </button>

                <ul v-if="ui.categoryOpen(category)" class="library__list">
                    <li v-for="definition in blocks" :key="definition.handle">
                        <button
                            type="button"
                            class="library__card"
                            :class="{ 'is-draggable': capabilities.structure }"
                            :disabled="!canPlace || blocked(definition) !== null"
                            :title="blocked(definition) ?? `${definition.label} — drag onto a column`"
                            @click="$emit('add', definition.handle)"
                            @pointerdown="
                                capabilities.structure &&
                                    $emit('dragStart', { kind: 'new', handle: definition.handle }, $event)
                            "
                        >
                            {{ definition.label }}
                            <small v-if="blocked(definition)">{{ blocked(definition) }}</small>
                        </button>
                    </li>
                </ul>
            </section>

            <p v-if="categories.length === 0" class="library__hint">No element matches that search.</p>
        </template>

        <template v-else-if="ui.libraryTab === 'patterns'">
            <ul class="library__list">
                <li v-for="pattern in visiblePatterns" :key="pattern.id">
                    <button
                        type="button"
                        class="library__card"
                        :disabled="
                            !capabilities.structure ||
                            (pattern.kind === 'block' && targetColumn === null)
                        "
                        :class="{ 'is-draggable': draggable({ kind: 'pattern', id: pattern.id, assetKind: pattern.kind }) }"
                        :title="
                            pattern.kind === 'block'
                                ? 'Drag onto a column, or click to use the selected one'
                                : 'Drag between sections, or click to append'
                        "
                        @click="$emit('insertPattern', pattern.id)"
                        @pointerdown="
                            draggable({ kind: 'pattern', id: pattern.id, assetKind: pattern.kind }) &&
                                $emit(
                                    'dragStart',
                                    { kind: 'pattern', id: pattern.id, assetKind: pattern.kind },
                                    $event,
                                )
                        "
                    >
                        {{ pattern.name }}
                        <small>{{ pattern.kind }}</small>
                    </button>
                </li>
            </ul>

            <p v-if="visiblePatterns.length === 0" class="library__hint">
                Nothing saved yet — select a section or block and use Save as pattern.
            </p>
        </template>

        <template v-else>
            <ul class="library__list">
                <li v-for="asset in visibleAssets" :key="asset.slug">
                    <button
                        type="button"
                        class="library__card"
                        :disabled="!capabilities.structure"
                        :class="{ 'is-draggable': draggable({ kind: 'library', slug: asset.slug, assetKind: asset.kind }) }"
                        :title="
                            asset.missingBlocks.length > 0
                                ? `This site is missing: ${asset.missingBlocks.join(', ')}`
                                : (asset.description ?? asset.name)
                        "
                        @click="$emit('insertLibrary', asset.slug)"
                        @pointerdown="
                            draggable({ kind: 'library', slug: asset.slug, assetKind: asset.kind }) &&
                                $emit(
                                    'dragStart',
                                    { kind: 'library', slug: asset.slug, assetKind: asset.kind },
                                    $event,
                                )
                        "
                    >
                        {{ asset.name }}
                        <small>
                            {{ asset.kind }} · {{ asset.downloads }} installs
                            <span v-if="asset.missingBlocks.length > 0" class="library__warning">
                                — missing {{ asset.missingBlocks.join(', ') }}
                            </span>
                        </small>
                    </button>
                </li>
            </ul>

            <p v-if="visibleAssets.length === 0" class="library__hint">
                No cloud asset matches that search.
            </p>

            <p v-for="collection in libraryCollections" :key="collection.slug" class="library__hint">
                {{ collection.name }} — {{ collection.assetCount }} assets by {{ collection.publisher }}
            </p>
        </template>
    </div>
</template>

<style scoped>
.library__search {
    display: block;
    margin-bottom: 8px;
}

.library__label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
}

.library__search input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: #0f1117;
    color: inherit;
    font: inherit;
}

.library__tabs {
    display: flex;
    gap: 3px;
    margin-bottom: 10px;
}

.library__tab {
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

.library__tab.is-active {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
}

.library__group {
    margin-bottom: 12px;
}

.library__heading {
    margin: 0 0 4px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.library__structures {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
}

.library__structure {
    padding: 5px 3px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.library__structure:hover:not(:disabled) {
    border-color: var(--builder-accent);
}

.library__structure:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.library__structure small {
    display: block;
    margin-top: 3px;
    font-size: 9px;
    opacity: 0.7;
    white-space: nowrap;
}

.library__bars {
    display: flex;
    gap: 2px;
    height: 16px;
}

.library__bar {
    border-radius: 2px;
    background: color-mix(in srgb, var(--builder-accent) 45%, transparent);
}

.library__category {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 4px 2px;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.65;
    cursor: pointer;
}

.library__category small {
    margin-left: auto;
    opacity: 0.7;
}

.library__list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.library__card {
    display: block;
    width: 100%;
    margin-bottom: 3px;
    padding: 5px 8px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.library__card:hover:not(:disabled) {
    border-color: var(--builder-accent);
}

.library__card.is-draggable:not(:disabled) {
    cursor: grab;
}

.library__card:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.library__card small {
    display: block;
    font-size: 10px;
    opacity: 0.7;
}

.library__hint {
    margin: 6px 0;
    font-size: 12px;
    opacity: 0.6;
}

.library__warning {
    color: #f0b45c;
}
</style>
