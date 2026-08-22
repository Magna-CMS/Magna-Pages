<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import { filterAssets, kindChips } from '../document/libraryFilter'
import type { LibraryAssetSummary, LibraryCollectionSummary } from '../stores/document'

/**
 * The library browser: the cloud catalog as a full surface, with a LIVE
 * preview of whatever is selected.
 *
 * Master–detail rather than a grid of thumbnails, deliberately: the
 * preview is a real render of the asset through this site's own theme —
 * more honest than any hub screenshot, since it shows what the thing will
 * look like HERE — and one live render at a time is what keeps that
 * affordable at any catalog size.
 *
 * The preview iframe is sandboxed with NO tokens: the document behind it
 * is hub content, rendered before the save path's walls have touched it.
 * The server validates and authorizes first; the sandbox is the second
 * wall. Scripts do not run in it, which for a preview is a feature twice.
 */

const props = defineProps<{
    assets: LibraryAssetSummary[]
    collections: LibraryCollectionSummary[]
    previewUrl: (slug: string) => string
    canStructure: boolean
}>()

const emit = defineEmits<{
    insert: [slug: string]
    /** A header or footer: installed as site chrome, not pasted into a page. */
    install: [slug: string]
    close: []
}>()

const root = ref<HTMLElement | null>(null)

// A modal takes focus: that is what makes it keyboard-dismissable at all,
// and what a screen reader expects aria-modal to mean.
onMounted(() => root.value?.focus())

const search = ref('')
const kind = ref('')

const chips = computed(() => kindChips(props.assets))

const visible = computed(() =>
    filterAssets(props.assets, { search: search.value, kind: kind.value }),
)

const selectedSlug = ref<string | null>(null)

/**
 * Whether the preview iframe has painted. The pane keeps the builder's
 * dark surface until it has — a white flash between selections is the
 * iframe's blank document showing through, and the fix is to not show
 * the iframe until it has something to say.
 */
const previewLoaded = ref(false)

watch(selectedSlug, () => {
    previewLoaded.value = false
})

const selected = computed(
    () => props.assets.find((asset) => asset.slug === selectedSlug.value) ?? null,
)

// Keep the selection inside the filtered set, and give the pane something
// to show the moment the browser opens.
watch(
    visible,
    (entries) => {
        if (!entries.some((entry) => entry.slug === selectedSlug.value)) {
            selectedSlug.value = entries[0]?.slug ?? null
        }
    },
    { immediate: true },
)

/**
 * Whether the selected asset is site chrome rather than page content.
 *
 * A header is an ordinary part that says what it is for, so nothing about
 * the catalog changes — only where the thing lands when you take it.
 */
const chromeRole = computed(() => {
    const role = selected.value?.role
    const isChrome = selected.value?.kind === 'part' && (role === 'header' || role === 'footer')

    return isChrome ? (role as 'header' | 'footer') : null
})

/** What the action will do, said before it is done. */
const insertHint = computed(() => {
    if (chromeRole.value !== null) {
        return `Saves as a ${chromeRole.value} you can choose, without changing the live site`
    }

    switch (selected.value?.kind) {
        case 'block':
            return 'Inserts into the selected column'
        case 'page':
            return 'Opens the import options'
        default:
            return 'Adds to the end of the page'
    }
})

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close')
    }
}
</script>

<template>
    <div
        ref="root"
        class="lib"
        role="dialog"
        aria-modal="true"
        aria-label="Cloud library"
        tabindex="-1"
        @keydown="onKeydown"
    >
        <header class="lib__bar">
            <h2>Cloud library</h2>

            <input
                v-model="search"
                type="search"
                class="lib__search"
                placeholder="Search the library…"
                aria-label="Search the library"
            />

            <div class="lib__chips" role="group" aria-label="Filter by kind">
                <button
                    type="button"
                    class="lib__chip"
                    :class="{ 'is-active': kind === '' }"
                    @click="kind = ''"
                >
                    All
                </button>
                <button
                    v-for="chip in chips"
                    :key="chip"
                    type="button"
                    class="lib__chip"
                    :class="{ 'is-active': kind === chip }"
                    @click="kind = chip"
                >
                    {{ chip }}
                </button>
            </div>

            <button
                type="button"
                class="lib__close"
                aria-label="Close the library"
                @click="$emit('close')"
            >
                ✕
            </button>
        </header>

        <div class="lib__body">
            <aside class="lib__list">
                <p v-for="collection in collections" :key="collection.slug" class="lib__collection">
                    {{ collection.name }} · {{ collection.assetCount }} assets · {{ collection.publisher }}
                </p>

                <button
                    v-for="asset in visible"
                    :key="asset.slug"
                    type="button"
                    class="lib__card"
                    :class="{ 'is-selected': asset.slug === selectedSlug }"
                    @click="selectedSlug = asset.slug"
                >
                    <span class="lib__card-name">{{ asset.name }}</span>
                    <span class="lib__card-meta">
                        <span class="lib__kind">{{ asset.kind }}</span>
                        {{ asset.downloads }} installs
                    </span>
                    <span v-if="asset.description" class="lib__card-desc">{{ asset.description }}</span>
                    <span v-if="asset.missingBlocks.length > 0" class="lib__warning">
                        Missing here: {{ asset.missingBlocks.join(', ') }}
                    </span>
                </button>

                <p v-if="visible.length === 0" class="lib__empty">Nothing matches that search.</p>
            </aside>

            <section class="lib__preview" aria-label="Asset preview">
                <template v-if="selected">
                    <div class="lib__stage">
                        <!-- sandbox with no tokens: hub content renders inert. -->
                        <iframe
                            :key="selected.slug"
                            class="lib__frame"
                            :class="{ 'is-ready': previewLoaded }"
                            :src="previewUrl(selected.slug)"
                            :title="`Preview of ${selected.name}`"
                            sandbox=""
                            @load="previewLoaded = true"
                        />

                        <div v-if="!previewLoaded" class="lib__loading" role="status">
                            <img :src="'/magna-logo.svg'" alt="" width="56" height="56" />
                            <span>Rendering preview…</span>
                        </div>
                    </div>
                    <footer class="lib__actions">
                        <span class="lib__hint">{{ insertHint }}</span>
                        <button
                            type="button"
                            class="lib__insert"
                            :aria-label="
                                chromeRole
                                    ? `Install ${selected.name} as a ${chromeRole}`
                                    : `Import ${selected.name} to the page`
                            "
                            :disabled="!canStructure"
                            :title="canStructure ? undefined : 'Needs the layout permission'"
                            @click="chromeRole ? $emit('install', selected.slug) : $emit('insert', selected.slug)"
                        >
                            {{ chromeRole ? `Install ${chromeRole}` : 'Import to Page' }}
                        </button>
                    </footer>
                </template>

                <p v-else class="lib__empty">Select an asset to preview it.</p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.lib {
    position: fixed;
    inset: 24px;
    z-index: 70;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--builder-border);
    border-radius: 10px;
    background: var(--builder-surface);
    box-shadow: 0 18px 60px rgb(0 0 0 / 55%);
}

.lib__bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--builder-border);
}

.lib__bar h2 {
    margin: 0;
    font-size: 14px;
    white-space: nowrap;
}

.lib__search {
    flex: 0 1 300px;
    padding: 6px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 6px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 13px;
}

.lib__chips {
    display: flex;
    gap: 4px;
}

.lib__chip {
    padding: 3px 11px;
    border: 1px solid var(--builder-border);
    border-radius: 999px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-transform: capitalize;
    cursor: pointer;
}

.lib__chip.is-active {
    background: var(--builder-accent);
    border-color: var(--builder-accent);
    color: #fff;
}

.lib__close {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
    opacity: 0.7;
}

.lib__close:hover {
    opacity: 1;
}

.lib__body {
    display: flex;
    flex: 1;
    min-height: 0;
}

.lib__list {
    flex: 0 0 300px;
    overflow-y: auto;
    padding: 12px;
    border-right: 1px solid var(--builder-border);
}

.lib__collection {
    margin: 0 0 8px;
    font-size: 11px;
    opacity: 0.6;
}

.lib__card {
    display: block;
    width: 100%;
    margin-bottom: 6px;
    padding: 9px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.lib__card:hover {
    border-color: color-mix(in srgb, var(--builder-accent) 60%, transparent);
}

.lib__card.is-selected {
    border-color: var(--builder-accent);
    background: color-mix(in srgb, var(--builder-accent) 12%, transparent);
}

.lib__card-name {
    display: block;
    font-weight: 600;
}

.lib__card-meta {
    display: block;
    margin-top: 2px;
    font-size: 11px;
    opacity: 0.7;
}

.lib__kind {
    display: inline-block;
    margin-right: 6px;
    padding: 0 6px;
    border: 1px solid var(--builder-border);
    border-radius: 999px;
    text-transform: capitalize;
}

.lib__card-desc {
    display: block;
    margin-top: 3px;
    font-size: 12px;
    opacity: 0.75;
}

.lib__warning {
    display: block;
    margin-top: 3px;
    font-size: 11px;
    color: #f0b45c;
}

.lib__empty {
    padding: 16px;
    font-size: 12px;
    opacity: 0.6;
    text-align: center;
}

.lib__preview {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.lib__stage {
    position: relative;
    display: flex;
    flex: 1;
    min-height: 0;
}

.lib__frame {
    flex: 1;
    width: 100%;
    border: 0;
    background: #fff;
    /* Invisible until painted: the blank document never shows. */
    opacity: 0;
    transition: opacity 0.25s ease;
}

.lib__frame.is-ready {
    opacity: 1;
}

.lib__loading {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    background: var(--builder-surface);
}

.lib__loading img {
    animation: lib-breathe 1.6s ease-in-out infinite;
}

.lib__loading span {
    font-size: 12px;
    letter-spacing: 0.04em;
    opacity: 0.6;
}

@keyframes lib-breathe {
    0%,
    100% {
        transform: scale(1);
        opacity: 0.85;
    }
    50% {
        transform: scale(1.06);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lib__loading img {
        animation: none;
    }

    .lib__frame {
        transition: none;
    }
}

.lib__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 14px;
    border-top: 1px solid var(--builder-border);
}

.lib__hint {
    font-size: 12px;
    opacity: 0.65;
}

.lib__insert {
    padding: 7px 16px;
    border: 0;
    border-radius: 6px;
    background: var(--builder-accent);
    color: #fff;
    font: inherit;
    cursor: pointer;
}

.lib__insert:hover:not(:disabled) {
    filter: brightness(1.1);
}

.lib__insert:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
