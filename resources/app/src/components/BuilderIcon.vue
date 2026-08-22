<script setup lang="ts">
import { computed } from 'vue'

import { useDocumentStore } from '../stores/document'

/**
 * One icon from the server's vocabulary.
 *
 * The geometry arrives in the bootstrap payload, so drawing an icon costs
 * nothing at runtime and a panel of forty tiles makes no requests. What
 * this component renders is markup the SERVER wrote, looked up by name —
 * never a name interpolated into markup, which is the difference between
 * a lookup and an injection.
 *
 * A name the vocabulary does not carry draws the fallback dot rather than
 * an empty box, so a plugin block with an icon this install has never
 * heard of still gets a tile that reads as a tile.
 */

const props = withDefaults(
    defineProps<{
        name: string | null
        size?: number
        label?: string | null
    }>(),
    { size: 20, label: null },
)

const store = useDocumentStore()

const body = computed(() => (props.name ? (store.icons[props.name] ?? null) : null))
</script>

<template>
    <svg
        class="bicon"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        :width="size"
        :height="size"
        :role="label ? 'img' : undefined"
        :aria-label="label ?? undefined"
        :aria-hidden="label ? undefined : 'true'"
        focusable="false"
    >
        <!-- eslint-disable-next-line vue/no-v-html -->
        <g v-if="body" v-html="body" />
        <circle v-else cx="12" cy="12" r="7" stroke-dasharray="2 3" />
    </svg>
</template>

<style scoped>
.bicon {
    flex: 0 0 auto;
    display: block;
}
</style>
