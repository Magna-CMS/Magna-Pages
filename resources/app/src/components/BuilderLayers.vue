<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import type { NodeAction, NodeActionKey } from '../document/actions'
import type { NodeKind } from '../document/locate'
import type { BlockNode, SectionNode } from '../document/types'
import BuilderNodeMenu from './BuilderNodeMenu.vue'

/**
 * The navigator: the document as a tree, and the guaranteed accessible
 * editing path (ATAG 2.0 target, 03-BUILDER §8).
 *
 * Every action the canvas offers on a node exists here too, as ordinary
 * buttons — a mouse gesture that has no keyboard equivalent is not an
 * editing path, it is an editing path for some people.
 *
 * The tree is rendered as ONE flat list of rows carrying their own depth,
 * rather than as nested markup. Three near-identical row templates for
 * section, column and block is three places to fix every time a row grows
 * an affordance, and the day one of them is missed a nested node quietly
 * behaves differently from a top-level one. Indentation is the depth.
 *
 * It also makes every row the same height, which is what lets a long
 * document be windowed later (12-BUILDER-REDESIGN §17) — and that is why
 * the row menu opens as an overlay rather than inline: an inline menu
 * changes the height of the row it belongs to.
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

interface LayerRow {
    id: string
    kind: NodeKind
    depth: number
    /** What the row reads as. */
    label: string
    /** What this node IS, for the labels that name a kind. */
    noun: string
    /** Title case of `noun`, which is what the menu announces itself as. */
    menuTitle: string
    badges: string[]
    expandable: boolean
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

function blockRows(blocks: BlockNode[], depth: number, into: LayerRow[]): void {
    for (const block of blocks) {
        const children = block.children ?? []

        into.push({
            id: block.id,
            kind: 'block',
            depth,
            label: nameOf(block.settings, block.block),
            noun: block.block,
            menuTitle: block.block,
            badges: badges(block.settings),
            expandable: children.length > 0,
        })

        if (children.length > 0 && !collapsed.value.has(block.id)) {
            blockRows(children, depth + 1, into)
        }
    }
}

/** The whole visible tree, in document order, collapse applied. */
const rows = computed<LayerRow[]>(() => {
    const list: LayerRow[] = []

    for (const section of props.sections) {
        list.push({
            id: section.id,
            kind: 'section',
            depth: 0,
            label: nameOf(section.settings, 'Section'),
            noun: 'section',
            menuTitle: 'Section',
            badges: badges(section.settings),
            expandable: true,
        })

        if (collapsed.value.has(section.id)) {
            continue
        }

        for (const column of section.columns ?? []) {
            list.push({
                id: column.id,
                kind: 'column',
                depth: 1,
                label: nameOf(column.settings, `Column (${column.span})`),
                noun: 'column',
                menuTitle: 'Column',
                // A column carries no badges of its own: everything a badge
                // reports is set on the section or on the block.
                badges: [],
                expandable: true,
            })

            if (!collapsed.value.has(column.id)) {
                blockRows(column.blocks ?? [], 2, list)
            }
        }
    }

    return list
})

/**
 * The open row menu, and where to draw it.
 *
 * Positioned against the viewport rather than rendered inside its row:
 * an inline menu makes one row taller than the rest, which is exactly what
 * a windowed list cannot have.
 */
const menu = ref<{ id: string; kind: NodeKind; title: string; at: { x: number; y: number } } | null>(
    null,
)

function openMenu(row: LayerRow, event: MouseEvent) {
    if (menu.value?.id === row.id) {
        menu.value = null

        return
    }

    const box = (event.currentTarget as HTMLElement).getBoundingClientRect()
    menu.value = {
        id: row.id,
        kind: row.kind,
        title: row.menuTitle,
        // Under the button that opened it, aligned to its left edge.
        at: { x: box.left, y: box.bottom },
    }
}

function pick(nodeId: string, key: string) {
    menu.value = null
    emit('act', nodeId, key as NodeActionKey)
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        menu.value = null
    }
}

function close() {
    menu.value = null
}

/**
 * A floating menu does not move with the row it belongs to, so anything
 * that moves the row closes it rather than leaving it stranded.
 *
 * Scroll is watched in the CAPTURE phase: the drawer scrolls, not the
 * window, and a scroll inside an element does not bubble.
 */
function watchWhileOpen(on: boolean) {
    // Bound through `window` on purpose: a detached `addEventListener`
    // reference throws "Illegal invocation" the moment it is called.
    const bind = on
        ? (type: string, handler: EventListener, capture?: boolean) =>
              window.addEventListener(type, handler, capture)
        : (type: string, handler: EventListener, capture?: boolean) =>
              window.removeEventListener(type, handler, capture)

    bind('keydown', onKeydown as EventListener)
    bind('resize', close)
    bind('scroll', close, true)
}

watch(() => menu.value !== null, watchWhileOpen)

onBeforeUnmount(() => watchWhileOpen(false))
</script>

<template>
    <nav class="layers" aria-label="Page structure">
        <h2 class="layers__heading">Navigator</h2>

        <ul class="layers__list">
            <li
                v-for="row in rows"
                :key="row.id"
                class="layers__item"
                :style="{ paddingLeft: `${row.depth * 12}px` }"
            >
                <div class="layers__row" :class="{ 'is-nested': row.depth > 0 }">
                    <button
                        v-if="row.expandable"
                        type="button"
                        class="layers__twisty"
                        :aria-expanded="!collapsed.has(row.id)"
                        :aria-label="`${collapsed.has(row.id) ? 'Expand' : 'Collapse'} ${row.noun}`"
                        @click="toggle(row.id)"
                    >
                        {{ collapsed.has(row.id) ? '▸' : '▾' }}
                    </button>
                    <span v-else class="layers__twisty layers__twisty--leaf" aria-hidden="true"></span>

                    <button
                        type="button"
                        class="layers__node"
                        :class="{ 'is-selected': selected === row.id }"
                        :aria-current="selected === row.id ? 'true' : undefined"
                        @click="$emit('select', row.id)"
                    >
                        {{ row.label }}
                        <span v-for="mark in row.badges" :key="mark" class="layers__badge">
                            {{ mark }}
                        </span>
                    </button>

                    <button
                        type="button"
                        class="layers__more"
                        :aria-label="`Actions for this ${row.noun}`"
                        @click="openMenu(row, $event)"
                    >
                        ⋮
                    </button>
                </div>
            </li>
        </ul>

        <BuilderNodeMenu
            v-if="menu"
            :at="menu.at"
            :actions="actionsFor(menu.id, menu.kind)"
            :label="menu.title"
            @pick="pick(menu.id, $event)"
            @close="close"
        />

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

/* Depth is drawn as indentation plus a guide, so a flat list still reads
   as a tree. */
.layers__item {
    box-sizing: border-box;
}

.layers__row {
    display: flex;
    align-items: center;
    gap: 2px;
}

.layers__row.is-nested {
    border-left: 1px solid var(--builder-border);
    padding-left: 6px;
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

/* Keeps a leaf row's label aligned with its siblings' labels. */
.layers__twisty--leaf {
    cursor: default;
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
