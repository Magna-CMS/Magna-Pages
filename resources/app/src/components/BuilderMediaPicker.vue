<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { ApiError, type BuilderApi, type MediaSummary } from '../api'

/**
 * Choosing a picture.
 *
 * A `media` field rendered as a plain text box before this existed, which
 * asked an editor to type a media id from memory — so a logo had no way to
 * get an image at all. Three ways in, because they are three different
 * situations: the picture is already here, it is on your computer, or you
 * have the SVG markup on a clipboard.
 *
 * Pasted markup goes through the SAME upload the file path uses, so it
 * passes the same SVG sanitiser. A shortcut that skipped it would be the
 * one somebody pasted a script into.
 */

const props = withDefaults(
    defineProps<{
        id: string
        value: string
        disabled: boolean
        api: BuilderApi
        /** What the FIELD accepts — 'image' for pictures, 'any' for files. */
        accept?: string
    }>(),
    { accept: 'image' },
)

const emit = defineEmits<{ pick: [mediaId: string] }>()

const open = ref(false)
const items = ref<MediaSummary[]>([])
const search = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

/** A refusal, told apart from an empty library — they look the same. */
const denied = ref(false)
const svg = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const chosen = computed(() => items.value.find((item) => item.id === props.value) ?? null)

/** A field asking for any file is not asking for a picture, and says so. */
const noun = computed(() => (props.accept === 'any' ? 'file' : 'picture'))
const nounPlural = computed(() => (props.accept === 'any' ? 'files' : 'pictures'))

/**
 * Only an image has a thumbnail. Everything else gets its extension, which
 * is the part of a filename someone actually scans for — an <img> pointed
 * at a PDF renders as a broken-image icon, which reads as "this is broken"
 * rather than "this is a PDF".
 */
function isImage(item: MediaSummary): boolean {
    return item.mime.startsWith('image/')
}

function extensionOf(item: MediaSummary): string {
    const dot = item.name.lastIndexOf('.')

    return dot === -1 ? 'file' : item.name.slice(dot + 1).toLowerCase()
}

async function load() {
    try {
        error.value = null
        denied.value = false
        items.value = (await props.api.media(search.value, props.accept)).media
    } catch (failure) {
        if (failure instanceof ApiError && (failure.status === 403 || failure.status === 401)) {
            denied.value = true

            return
        }
        error.value = failure instanceof Error ? failure.message : String(failure)
    }
}

onMounted(load)

async function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) {
        return
    }

    await add(() => props.api.uploadMedia({ file }))
}

async function onPasteSvg() {
    if (svg.value.trim() === '') {
        return
    }

    await add(() => props.api.uploadMedia({ svg: svg.value }))
    svg.value = ''
}

/** One add path, whichever door it came through. */
async function add(upload: () => Promise<MediaSummary>) {
    busy.value = true
    error.value = null
    try {
        const media = await upload()
        // In the list AND chosen: somebody who just added a picture meant
        // to use it, and making them find it again is a step for nothing.
        items.value = [media, ...items.value]
        emit('pick', media.id)
        open.value = false
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
        busy.value = false
        if (fileInput.value) {
            fileInput.value.value = ''
        }
    }
}
</script>

<template>
    <div class="media">
        <button
            :id="id"
            type="button"
            class="media__current"
            :disabled="disabled"
            :aria-expanded="open"
            @click="open = !open"
        >
            <img
                v-if="chosen && isImage(chosen)"
                :src="chosen.url"
                :alt="''"
                class="media__thumb"
            />
            <span v-else-if="chosen" class="media__ext" aria-hidden="true">
                {{ extensionOf(chosen) }}
            </span>
            <span>{{ chosen ? chosen.name : (value ? `Chosen ${noun}` : `Choose a ${noun}`) }}</span>
            <span aria-hidden="true">{{ open ? '▾' : '▸' }}</span>
        </button>

        <button
            v-if="value && !disabled"
            type="button"
            class="media__clear"
            @click="$emit('pick', '')"
        >
            Remove
        </button>

        <div v-if="open" class="media__panel">
            <input
                v-model="search"
                type="search"
                class="media__search"
                :placeholder="`Search ${nounPlural}…`"
                :aria-label="`Search ${nounPlural}`"
                @input="load"
            />

            <div class="media__grid" role="listbox" :aria-label="nounPlural">
                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="media__item"
                    :class="{ 'is-current': item.id === value }"
                    role="option"
                    :aria-selected="item.id === value"
                    :title="item.name"
                    @click="$emit('pick', item.id); open = false"
                >
                    <img v-if="isImage(item)" :src="item.url" :alt="''" />
                    <span v-else class="media__ext" aria-hidden="true">{{ extensionOf(item) }}</span>
                </button>
            </div>

            <!-- An empty grid and a refused one look identical, so they
                 must not read identically: media permissions are separate
                 from page ones, and an editor who lacks them should learn
                 that rather than think the feature is broken. -->
            <p v-if="denied" class="media__empty">
                Choosing {{ nounPlural }} needs the media permission — ask an admin.
            </p>
            <p v-else-if="items.length === 0" class="media__empty">
                {{ search === '' ? `No ${nounPlural} yet — upload one below.` : 'Nothing matches that.' }}
            </p>

            <div class="media__add">
                <label class="media__upload">
                    <span>Upload a {{ noun }}</span>
                    <input
                        ref="fileInput"
                        type="file"
                        :accept="accept === 'any' ? undefined : 'image/*,.svg'"
                        :disabled="busy"
                        @change="onFile"
                    />
                </label>

                <!-- A wordmark is often an SVG somebody already has on a
                     clipboard, and saving it to a file first is a step for
                     nothing. It is uploaded like any other file, so it is
                     sanitised like any other SVG. -->
                <label class="media__paste">
                    <span>…or paste SVG markup</span>
                    <textarea
                        v-model="svg"
                        rows="3"
                        placeholder="&lt;svg …&gt;"
                        :disabled="busy"
                    />
                </label>
                <button type="button" :disabled="busy || svg.trim() === ''" @click="onPasteSvg">
                    {{ busy ? 'Adding…' : 'Add that SVG' }}
                </button>
            </div>

            <p v-if="error" class="media__error" role="alert">{{ error }}</p>
        </div>
    </div>
</template>

<style scoped>
.media__current {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 5px 8px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.media__current:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.media__current span:nth-of-type(1) {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.media__thumb {
    width: 22px;
    height: 22px;
    object-fit: contain;
}

/* What a non-image gets instead of a thumbnail. */
.media__ext {
    flex: none;
    padding: 1px 5px;
    border: 1px solid var(--builder-border);
    border-radius: 3px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.8;
}

.media__clear {
    margin-top: 4px;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 11px;
    text-decoration: underline;
    opacity: 0.7;
    cursor: pointer;
}

.media__panel {
    margin-top: 4px;
    padding: 6px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
}

.media__search {
    width: 100%;
    margin-bottom: 6px;
    padding: 4px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 12px;
}

.media__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(52px, 1fr));
    gap: 4px;
    max-height: 190px;
    overflow-y: auto;
}

.media__item {
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    padding: 3px;
    border: 1px solid transparent;
    border-radius: 4px;
    background: #0f1117;
    cursor: pointer;
}

.media__item img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.media__item:hover,
.media__item.is-current {
    border-color: var(--builder-accent);
}

.media__add {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--builder-border);
}

.media__upload span,
.media__paste span {
    display: block;
    margin-bottom: 3px;
    font-size: 11px;
    opacity: 0.75;
}

.media__paste {
    display: block;
    margin-top: 8px;
}

.media__paste textarea {
    width: 100%;
    padding: 5px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 11px;
}

.media__add > button {
    margin-top: 5px;
    padding: 4px 12px;
    border: 0;
    border-radius: 999px;
    background: var(--builder-accent);
    color: #fff;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}

.media__add > button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.media__empty,
.media__error {
    margin: 6px 0 0;
    font-size: 12px;
    opacity: 0.7;
}

.media__error {
    color: #f0b45c;
    opacity: 1;
}
</style>
