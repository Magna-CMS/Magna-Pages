<script setup lang="ts">
import { computed } from 'vue'

import { needsImportFlow, type DragSource } from '../document/placement'
import BuilderIcon from './BuilderIcon.vue'
import BuilderStructurePicker from './BuilderStructurePicker.vue'
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
    targetParent: string | null
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
    /** The Cloud tab opens the full browser; the list below stays for drag. */
    openBrowser: []
}>()

const ui = useUiStore()

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

const canPlace = computed(() => props.capabilities.structure && props.targetParent !== null)

function blocked(definition: BlockDefinition): string | null {
    if (!props.capabilities.structure) {
        return 'Needs layout permission'
    }
    if (definition.requiresPermission && !props.capabilities.style) {
        return `Needs ${definition.requiresPermission}`
    }
    if (props.targetParent === null) {
        return 'Pick a column first'
    }

    return null
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
                :id="`library-tab-${tab}`"
                :key="tab"
                type="button"
                role="tab"
                class="library__tab"
                :class="{ 'is-active': ui.libraryTab === tab }"
                :aria-selected="ui.libraryTab === tab"
                aria-controls="library-panel"
                :tabindex="ui.libraryTab === tab ? 0 : -1"
                @click="ui.libraryTab = tab; tab === 'cloud' && $emit('openBrowser')"
            >
                {{ tab }}
            </button>
        </div>

        <!-- One panel, whichever tab fills it. Tabs that control nothing
             are announced as tabs that control nothing. -->
        <div
            id="library-panel"
            role="tabpanel"
            :aria-labelledby="`library-tab-${ui.libraryTab}`"
        >
        <template v-if="ui.libraryTab === 'elements'">
            <section class="library__group">
                <h3 class="library__heading">Add section</h3>
                <p class="library__hint">Pick a column structure to start a row.</p>

                <BuilderStructurePicker
                    :disabled="!capabilities.structure"
                    @pick="$emit('addSection', $event)"
                />
            </section>

            <p v-if="!canPlace && capabilities.structure" class="library__hint">
                Select a column (or a section) to place an element in.
            </p>

            <!--
                A grid of tiles rather than a list of rows.

                An element is recognised by its shape long before its name
                is read, so the icon does the finding and the label only
                confirms it. Three to a row turns a column of twenty rows
                into seven, which is the difference between scrolling to
                find the image block and seeing it.

                A blocked element stays VISIBLE and says why. An editor who
                cannot find the HTML block assumes it is missing and files a
                bug; one who sees it dimmed learns what to ask an admin for.
            -->
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

                <div v-if="ui.categoryOpen(category)" class="library__grid">
                    <button
                        v-for="definition in blocks"
                        :key="definition.handle"
                        type="button"
                        class="library__tile"
                        :class="{ 'is-draggable': capabilities.structure }"
                        :disabled="!canPlace || blocked(definition) !== null"
                        :title="blocked(definition) ?? `${definition.label} — drag onto a column`"
                        @click="$emit('add', definition.handle)"
                        @pointerdown="
                            capabilities.structure &&
                                $emit('dragStart', { kind: 'new', handle: definition.handle }, $event)
                        "
                    >
                        <BuilderIcon :name="definition.icon" :size="22" />
                        <span class="library__tile-label">{{ definition.label }}</span>
                    </button>
                </div>
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
                            (pattern.kind === 'block' && targetParent === null)
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

/*
 * The element grid. Three to a row at the panel's usual width, and it
 * reflows rather than clipping when the panel is dragged narrower — the
 * panel is resizable, so a fixed three would eventually crush the labels.
 */
.library__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: 4px;
}

.library__tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 68px;
    padding: 9px 4px;
    border: 1px solid var(--builder-border);
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.12s ease, background 0.12s ease;
}

.library__tile:hover:not(:disabled) {
    border-color: var(--builder-accent);
    background: color-mix(in srgb, var(--builder-accent) 10%, transparent);
}

.library__tile:focus-visible {
    outline: 2px solid var(--builder-accent);
    outline-offset: 1px;
}

.library__tile.is-draggable:not(:disabled) {
    cursor: grab;
}

.library__tile:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.library__tile-label {
    font-size: 10px;
    line-height: 1.25;
    /* Two lines, then ellipsis: "Testimonials" must not push the tile
       taller than its neighbours and break the grid's rhythm. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
