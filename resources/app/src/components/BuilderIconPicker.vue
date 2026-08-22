<script setup lang="ts">
import { computed, ref } from 'vue'

import BuilderIcon from './BuilderIcon.vue'
import { useDocumentStore } from '../stores/document'

/**
 * Choosing an icon by looking at icons.
 *
 * An `icon` field used to render as a text input, which asked an editor to
 * type `core:chevron-right` from memory — a control that only works if you
 * already know the answer. The vocabulary is finite and already in the
 * browser, so the honest control is the vocabulary itself.
 *
 * In flow rather than floating: the inspector is a scrolling column, and a
 * popover would need its own scroll and resize handling to stay attached
 * to a field moving under it. Pushing the fields below down for a moment
 * is the cheaper honesty — the same trade the tag suggester makes.
 */

defineProps<{
    id: string
    value: string
    disabled: boolean
}>()

const emit = defineEmits<{ pick: [name: string] }>()

const store = useDocumentStore()

const open = ref(false)
const search = ref('')

const names = computed(() => Object.keys(store.icons))

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase()
    if (needle === '') {
        return names.value
    }

    return names.value.filter((name) => name.toLowerCase().includes(needle))
})

/** `core:chevron-right` reads as "chevron right" once it is a label. */
function pretty(name: string): string {
    return (name.split(':')[1] ?? name).replace(/-/g, ' ')
}

function choose(name: string) {
    emit('pick', name)
    open.value = false
}
</script>

<template>
    <div class="iconpick">
        <button
            :id="id"
            type="button"
            class="iconpick__current"
            :disabled="disabled"
            :aria-expanded="open"
            @click="open = !open"
        >
            <BuilderIcon :name="value || null" :size="18" />
            <span class="iconpick__name">{{ value ? pretty(value) : 'Choose an icon' }}</span>
            <span aria-hidden="true">{{ open ? '▾' : '▸' }}</span>
        </button>

        <div v-if="open" class="iconpick__panel">
            <input
                v-model="search"
                type="search"
                class="iconpick__search"
                placeholder="Search icons…"
                aria-label="Search icons"
            />

            <div class="iconpick__grid" role="listbox" aria-label="Icons">
                <button
                    v-for="name in visible"
                    :key="name"
                    type="button"
                    class="iconpick__option"
                    :class="{ 'is-current': name === value }"
                    role="option"
                    :aria-selected="name === value"
                    :title="pretty(name)"
                    @click="choose(name)"
                >
                    <BuilderIcon :name="name" :size="18" />
                </button>
            </div>

            <p v-if="visible.length === 0" class="iconpick__empty">No icon matches that.</p>
        </div>
    </div>
</template>

<style scoped>
.iconpick__current {
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

.iconpick__current:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.iconpick__name {
    flex: 1;
    text-transform: capitalize;
}

.iconpick__panel {
    margin-top: 4px;
    padding: 6px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
}

.iconpick__search {
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

.iconpick__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(34px, 1fr));
    gap: 3px;
    /* Enough to browse, short enough that the fields below stay reachable
       without scrolling past the whole vocabulary. */
    max-height: 190px;
    overflow-y: auto;
}

.iconpick__option {
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    border: 1px solid transparent;
    border-radius: 4px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.iconpick__option:hover {
    border-color: var(--builder-accent);
}

.iconpick__option.is-current {
    border-color: var(--builder-accent);
    background: color-mix(in srgb, var(--builder-accent) 20%, transparent);
}

.iconpick__empty {
    margin: 6px 0 2px;
    font-size: 12px;
    opacity: 0.6;
}
</style>
