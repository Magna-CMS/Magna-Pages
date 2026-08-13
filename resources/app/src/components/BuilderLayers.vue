<script setup lang="ts">
import { ref } from 'vue'

import type { NodeAction, NodeActionKey } from '../document/actions'
import type { NodeKind } from '../document/locate'
import type { SectionNode } from '../document/types'
import BuilderNodeMenu from './BuilderNodeMenu.vue'

/**
 * The navigator: the document as a tree, and the guaranteed accessible
 * editing path (ATAG 2.0 target, 03-BUILDER §8).
 *
 * Every action the canvas offers on a node exists here too, as ordinary
 * buttons — a mouse gesture that has no keyboard equivalent is not an
 * editing path, it is an editing path for some people.
 */

const props = defineProps<{
    sections: SectionNode[]
    selected: string | null
    /** The actions for the node currently open in a row menu. */
    actionsFor: (nodeId: string, kind: NodeKind) => NodeAction[]
}>()

const emit = defineEmits<{
    select: [node: string]
    act: [node: string, action: NodeActionKey]
}>()

/**
 * Collapsed nodes, by id. Session state, not persisted: which parts of a
 * document you folded away is a working posture, and restoring yesterday's
 * fold on a document you have since restructured is noise.
 */
const collapsed = ref<Set<string>>(new Set())

function toggle(nodeId: string) {
    const next = new Set(collapsed.value)
    next.has(nodeId) ? next.delete(nodeId) : next.add(nodeId)
    collapsed.value = next
}

const openMenu = ref<string | null>(null)

function menuFor(nodeId: string, kind: NodeKind): NodeAction[] {
    return props.actionsFor(nodeId, kind)
}

function pick(nodeId: string, key: string) {
    openMenu.value = null
    emit('act', nodeId, key as NodeActionKey)
}

/**
 * What a row is called. A named node keeps its name; everything else
 * falls back to what it is, so an unnamed document reads exactly as it
 * did before naming existed.
 */
function nameOf(settings: Record<string, unknown> | undefined, fallback: string): string {
    const label = settings?.label

    return typeof label === 'string' && label.trim() !== '' ? label : fallback
}

/** Badges say what the canvas cannot: this node is not what it appears. */
function badges(settings: Record<string, unknown> | undefined): string[] {
    const marks: string[] = []
    if (Array.isArray(settings?.conditions) && settings.conditions.length > 0) {
        marks.push('conditional')
    }

    const visibility = settings?.visibility
    if (typeof visibility === 'object' && visibility !== null) {
        const hidden = Object.entries(visibility as Record<string, unknown>)
            .filter(([, shown]) => shown === false)
            .map(([device]) => device)
        if (hidden.length > 0) {
            marks.push(`hidden: ${hidden.join(', ')}`)
        }
    }

    if (typeof settings?.variant === 'string') {
        marks.push(`variant ${settings.variant}`)
    }
    if (settings?.locked === true) {
        marks.push('locked')
    }

    return marks
}
</script>

<template>
    <nav class="layers" aria-label="Page structure">
        <h2 class="layers__heading">Navigator</h2>

        <ul class="layers__list">
            <li v-for="section in sections" :key="section.id">
                <div class="layers__row">
                    <button
                        type="button"
                        class="layers__twisty"
                        :aria-expanded="!collapsed.has(section.id)"
                        :aria-label="`${collapsed.has(section.id) ? 'Expand' : 'Collapse'} section`"
                        @click="toggle(section.id)"
                    >
                        {{ collapsed.has(section.id) ? '▸' : '▾' }}
                    </button>

                    <button
                        type="button"
                        class="layers__node"
                        :class="{ 'is-selected': selected === section.id }"
                        :aria-current="selected === section.id ? 'true' : undefined"
                        @click="$emit('select', section.id)"
                    >
                        {{ nameOf(section.settings, 'Section') }}
                        <span v-for="mark in badges(section.settings)" :key="mark" class="layers__badge">
                            {{ mark }}
                        </span>
                    </button>

                    <button
                        type="button"
                        class="layers__more"
                        :aria-label="'Actions for this section'"
                        @click="openMenu = openMenu === section.id ? null : section.id"
                    >
                        ⋮
                    </button>
                </div>

                <BuilderNodeMenu
                    v-if="openMenu === section.id"
                    :at="null"
                    :actions="menuFor(section.id, 'section')"
                    label="Section"
                    @pick="pick(section.id, $event)"
                />

                <ul v-if="!collapsed.has(section.id)" class="layers__list layers__list--nested">
                    <li v-for="column in section.columns ?? []" :key="column.id">
                        <div class="layers__row">
                            <button
                                type="button"
                                class="layers__twisty"
                                :aria-expanded="!collapsed.has(column.id)"
                                :aria-label="`${collapsed.has(column.id) ? 'Expand' : 'Collapse'} column`"
                                @click="toggle(column.id)"
                            >
                                {{ collapsed.has(column.id) ? '▸' : '▾' }}
                            </button>

                            <button
                                type="button"
                                class="layers__node"
                                :class="{ 'is-selected': selected === column.id }"
                                @click="$emit('select', column.id)"
                            >
                                {{ nameOf(column.settings, `Column (${column.span})`) }}
                            </button>

                            <button
                                type="button"
                                class="layers__more"
                                :aria-label="'Actions for this column'"
                                @click="openMenu = openMenu === column.id ? null : column.id"
                            >
                                ⋮
                            </button>
                        </div>

                        <BuilderNodeMenu
                            v-if="openMenu === column.id"
                            :at="null"
                            :actions="menuFor(column.id, 'column')"
                            label="Column"
                            @pick="pick(column.id, $event)"
                        />

                        <ul v-if="!collapsed.has(column.id)" class="layers__list layers__list--nested">
                            <li v-for="block in column.blocks ?? []" :key="block.id">
                                <div class="layers__row">
                                    <button
                                        type="button"
                                        class="layers__node"
                                        :class="{ 'is-selected': selected === block.id }"
                                        @click="$emit('select', block.id)"
                                    >
                                        {{ nameOf(block.settings, block.block) }}
                                        <span
                                            v-for="mark in badges(block.settings)"
                                            :key="mark"
                                            class="layers__badge"
                                        >
                                            {{ mark }}
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        class="layers__more"
                                        :aria-label="`Actions for this ${block.block}`"
                                        @click="openMenu = openMenu === block.id ? null : block.id"
                                    >
                                        ⋮
                                    </button>
                                </div>

                                <BuilderNodeMenu
                                    v-if="openMenu === block.id"
                                    :at="null"
                                    :actions="menuFor(block.id, 'block')"
                                    :label="block.block"
                                    @pick="pick(block.id, $event)"
                                />
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

.layers__row {
    display: flex;
    align-items: center;
    gap: 2px;
}

.layers__node {
    flex: 1;
    display: block;
    min-width: 0;
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

.layers__twisty,
.layers__more {
    flex: 0 0 auto;
    width: 18px;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: transparent;
    color: inherit;
    font: inherit;
    opacity: 0.6;
    cursor: pointer;
}

.layers__twisty:hover,
.layers__more:hover {
    opacity: 1;
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
