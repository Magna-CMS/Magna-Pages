<script setup lang="ts">
import type { SectionNode } from '../document/types'

/**
 * The layers panel: the document as a tree.
 *
 * Also the accessible editing path (ATAG 2.0 target, 03-BUILDER §8) — every
 * node reachable and selectable by keyboard here, which the canvas overlay
 * alone cannot promise.
 */

defineProps<{
    sections: SectionNode[]
    selected: string | null
}>()

defineEmits<{ select: [node: string] }>()
</script>

<template>
    <nav class="layers" aria-label="Page structure">
        <h2 class="layers__heading">Layers</h2>

        <ul class="layers__list">
            <li v-for="section in sections" :key="section.id">
                <button
                    type="button"
                    class="layers__node"
                    :class="{ 'is-selected': selected === section.id }"
                    :aria-current="selected === section.id ? 'true' : undefined"
                    @click="$emit('select', section.id)"
                >
                    Section
                    <!-- Conditioned nodes show on the canvas regardless, so
                         the tree is where "this hides for some visitors"
                         has to be visible. -->
                    <span
                        v-if="Array.isArray(section.settings?.conditions) && section.settings.conditions.length > 0"
                        class="layers__badge"
                        title="Shown conditionally"
                    >
                        conditional
                    </span>
                </button>

                <ul class="layers__list layers__list--nested">
                    <li v-for="column in section.columns ?? []" :key="column.id">
                        <button
                            type="button"
                            class="layers__node"
                            :class="{ 'is-selected': selected === column.id }"
                            @click="$emit('select', column.id)"
                        >
                            Column ({{ column.span }})
                        </button>

                        <ul class="layers__list layers__list--nested">
                            <li v-for="block in column.blocks ?? []" :key="block.id">
                                <button
                                    type="button"
                                    class="layers__node"
                                    :class="{ 'is-selected': selected === block.id }"
                                    @click="$emit('select', block.id)"
                                >
                                    {{ block.block }}
                                </button>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>

        <p v-if="sections.length === 0" class="layers__empty">
            This page has no sections yet.
        </p>
    </nav>
</template>

<style scoped>
.layers__heading {
    margin: 0 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.layers__list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.layers__list--nested {
    margin-left: 10px;
    border-left: 1px solid var(--builder-border);
    padding-left: 6px;
}

.layers__node {
    display: block;
    width: 100%;
    padding: 3px 6px;
    border: 0;
    border-radius: 3px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.layers__node:hover {
    background: color-mix(in srgb, var(--builder-accent) 15%, transparent);
}

.layers__node.is-selected {
    background: var(--builder-accent);
    color: #fff;
}

.layers__empty {
    opacity: 0.6;
}

.layers__badge {
    margin-left: 6px;
    padding: 0 5px;
    border-radius: 999px;
    background: #4a3a12;
    color: #f0b45c;
    font-size: 10px;
}
</style>
