<script setup lang="ts">
import { computed } from 'vue'

import type {
    LibraryAssetSummary,
    LibraryCollectionSummary,
    PatternSummary,
} from '../stores/document'
import type { BlockDefinition, Capabilities, SectionNode } from '../document/types'

/**
 * The Add panel: installed blocks, grouped by the category their definition
 * declares.
 *
 * A block whose permission the actor lacks is shown disabled with the reason
 * rather than hidden — an editor who cannot find the HTML block assumes it
 * is missing and files a bug; one who sees it greyed out learns what to ask
 * their admin for.
 */

const props = defineProps<{
    registry: BlockDefinition[]
    sections: SectionNode[]
    targetColumn: string | null
    capabilities: Capabilities
    patterns: PatternSummary[]
    libraryAssets: LibraryAssetSummary[]
    libraryCollections: LibraryCollectionSummary[]
}>()

defineEmits<{
    add: [handle: string]
    addSection: []
    insertPattern: [id: string, kind: string]
    insertLibrary: [slug: string]
}>()

const categories = computed(() => {
    const groups = new Map<string, BlockDefinition[]>()

    for (const definition of props.registry) {
        const list = groups.get(definition.category) ?? []
        list.push(definition)
        groups.set(definition.category, list)
    }

    return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))
})

const canPlace = computed(() => props.capabilities.structure && props.targetColumn !== null)

function blocked(definition: BlockDefinition): string | null {
    if (!props.capabilities.structure) {
        return 'Needs layout permission'
    }
    if (definition.requiresPermission && !props.capabilities.style) {
        return `Needs ${definition.requiresPermission}`
    }

    return null
}
</script>

<template>
    <section class="add">
        <h2 class="add__heading">Add</h2>

        <button
            type="button"
            class="add__section"
            :disabled="!capabilities.structure"
            @click="$emit('addSection')"
        >
            + Section
        </button>

        <p v-if="!canPlace && capabilities.structure" class="add__hint">
            Select a column to place a block in.
        </p>

        <div v-if="patterns.length > 0" class="add__group">
            <h3 class="add__category">My library</h3>

            <ul class="add__list">
                <li v-for="pattern in patterns" :key="pattern.id">
                    <button
                        type="button"
                        class="add__block"
                        :disabled="
                            !capabilities.structure ||
                            (pattern.kind === 'block' && targetColumn === null)
                        "
                        :title="pattern.kind === 'block' ? 'Inserts into the selected column' : 'Appends a section'"
                        @click="$emit('insertPattern', pattern.id, pattern.kind)"
                    >
                        {{ pattern.name }}
                        <small>{{ pattern.kind }}</small>
                    </button>
                </li>
            </ul>
        </div>

        <div v-if="libraryAssets.length > 0" class="add__group">
            <h3 class="add__category">Cloud library</h3>

            <ul class="add__list">
                <li v-for="asset in libraryAssets" :key="asset.slug">
                    <button
                        type="button"
                        class="add__block"
                        :disabled="!capabilities.structure"
                        :title="
                            asset.missingBlocks.length > 0
                                ? `This site is missing: ${asset.missingBlocks.join(', ')}`
                                : (asset.description ?? asset.name)
                        "
                        @click="$emit('insertLibrary', asset.slug)"
                    >
                        {{ asset.name }}
                        <small>
                            {{ asset.kind }} · {{ asset.downloads }} installs
                            <span v-if="asset.missingBlocks.length > 0" class="add__warning">
                                — missing {{ asset.missingBlocks.join(', ') }}
                            </span>
                        </small>
                    </button>
                </li>
            </ul>

            <p v-for="collection in libraryCollections" :key="collection.slug" class="add__hint">
                {{ collection.name }} — {{ collection.assetCount }} assets by {{ collection.publisher }}
            </p>
        </div>

        <div v-for="[category, blocks] in categories" :key="category" class="add__group">
            <h3 class="add__category">{{ category }}</h3>

            <ul class="add__list">
                <li v-for="definition in blocks" :key="definition.handle">
                    <button
                        type="button"
                        class="add__block"
                        :disabled="!canPlace || blocked(definition) !== null"
                        :title="blocked(definition) ?? definition.label"
                        @click="$emit('add', definition.handle)"
                    >
                        {{ definition.label }}
                        <small v-if="blocked(definition)">{{ blocked(definition) }}</small>
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>

<style scoped>
.add__heading {
    margin: 16px 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.add__category {
    margin: 10px 0 4px;
    font-size: 11px;
    opacity: 0.5;
}

.add__list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.add__block,
.add__section {
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

.add__block:hover:not(:disabled),
.add__section:hover:not(:disabled) {
    border-color: var(--builder-accent);
}

.add__block:disabled,
.add__section:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.add__block small {
    display: block;
    font-size: 10px;
    opacity: 0.7;
}

.add__hint {
    margin: 6px 0;
    font-size: 12px;
    opacity: 0.6;
}

.add__warning {
    color: #f0b45c;
}
</style>
