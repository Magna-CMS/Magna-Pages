<script setup lang="ts">
import { useUiStore, type Drawer } from '../stores/ui'

/**
 * The bottom dock: navigator, checks, comments and design, as drawers that
 * open over the canvas rather than as rails that steal width from it.
 *
 * These are all things an editor consults occasionally and closes again.
 * Permanent chrome for an occasional surface is how a builder ends up with
 * a canvas the size of a postcard.
 */

const TABS: { key: Exclude<Drawer, null>; label: string }[] = [
    { key: 'layers', label: 'Navigator' },
    { key: 'design', label: 'Design' },
    { key: 'checks', label: 'Checks' },
    { key: 'comments', label: 'Comments' },
]

defineProps<{ commentCount: number | null }>()

const ui = useUiStore()
</script>

<template>
    <div class="dock" :class="{ 'is-open': ui.drawer !== null }">
        <div v-if="ui.drawer !== null" class="dock__drawer" role="region" :aria-label="ui.drawer">
            <div class="dock__scroll">
                <slot v-if="ui.drawer === 'layers'" name="layers" />
                <slot v-else-if="ui.drawer === 'design'" name="design" />
                <slot v-else-if="ui.drawer === 'checks'" name="checks" />
                <slot v-else name="comments" />
            </div>
        </div>

        <div class="dock__bar">
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                class="dock__tab"
                :class="{ 'is-active': ui.drawer === tab.key }"
                :aria-pressed="ui.drawer === tab.key"
                @click="ui.toggleDrawer(tab.key)"
            >
                {{ tab.label }}
                <span v-if="tab.key === 'comments' && commentCount" class="dock__count">
                    {{ commentCount }}
                </span>
            </button>

            <span class="dock__spacer" />

            <button
                v-if="ui.drawer !== null"
                type="button"
                class="dock__tab"
                @click="ui.closeDrawer()"
            >
                Close
            </button>
        </div>
    </div>
</template>

<style scoped>
.dock {
    display: flex;
    flex-direction: column;
    border-top: 1px solid var(--builder-border);
    background: var(--builder-surface);
}

.dock__drawer {
    height: 34vh;
    min-height: 180px;
    border-bottom: 1px solid var(--builder-border);
}

.dock__scroll {
    height: 100%;
    overflow-y: auto;
    padding: 10px 14px;
}

.dock__bar {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
}

.dock__tab {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border: 1px solid transparent;
    border-radius: 999px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}

.dock__tab:hover {
    border-color: var(--builder-border);
}

.dock__tab.is-active {
    background: color-mix(in srgb, var(--builder-accent) 25%, transparent);
    border-color: var(--builder-accent);
}

.dock__count {
    padding: 0 5px;
    border-radius: 999px;
    background: var(--builder-accent);
    color: #fff;
    font-size: 10px;
}

.dock__spacer {
    flex: 1;
}
</style>
