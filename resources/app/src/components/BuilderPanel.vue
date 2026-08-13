<script setup lang="ts">
import { ref } from 'vue'

import { useUiStore } from '../stores/ui'

/**
 * The left panel shell: one surface with two modes.
 *
 * Elementor's core insight is that the editor only ever answers two
 * questions — "what can I add?" and "what does this do?" — so one panel
 * switches between them instead of two rails competing for the eye. The
 * shell owns the mode switch, the width, and the collapse; what fills it
 * is the caller's business.
 */

const props = defineProps<{
    /** Shown next to the Inspect tab so the mode names the selection. */
    selectionLabel: string | null
}>()

const ui = useUiStore()
const resizing = ref(false)

function onResizeStart(event: PointerEvent) {
    const handle = event.currentTarget as HTMLElement
    handle.setPointerCapture(event.pointerId)
    resizing.value = true
}

function onResizeMove(event: PointerEvent) {
    if (resizing.value) {
        // The panel's left edge is the viewport's, so the pointer's x IS
        // the width — no offset bookkeeping to drift out of sync.
        ui.setPanelWidth(event.clientX)
    }
}

function onResizeEnd(event: PointerEvent) {
    ;(event.currentTarget as HTMLElement).releasePointerCapture(event.pointerId)
    resizing.value = false
}

/** Keyboard resizing: a drag handle nobody can reach is not a control. */
function onResizeKey(event: KeyboardEvent) {
    const step = event.shiftKey ? 40 : 10

    if (event.key === 'ArrowLeft') {
        event.preventDefault()
        ui.setPanelWidth(ui.panelWidth - step)
    }
    if (event.key === 'ArrowRight') {
        event.preventDefault()
        ui.setPanelWidth(ui.panelWidth + step)
    }
}
</script>

<template>
    <aside
        class="panel"
        :class="{ 'is-collapsed': ui.panelCollapsed }"
        :style="ui.panelCollapsed ? undefined : { width: `${ui.panelWidth}px` }"
    >
        <button
            type="button"
            class="panel__collapse"
            :aria-expanded="!ui.panelCollapsed"
            :title="ui.panelCollapsed ? 'Show the panel' : 'Hide the panel'"
            @click="ui.togglePanel()"
        >
            {{ ui.panelCollapsed ? '›' : '‹' }}
        </button>

        <template v-if="!ui.panelCollapsed">
            <div class="panel__modes" role="tablist" aria-label="Panel mode">
                <button
                    type="button"
                    role="tab"
                    class="panel__mode"
                    :class="{ 'is-active': ui.mode === 'library' }"
                    :aria-selected="ui.mode === 'library'"
                    @click="ui.browse()"
                >
                    Add
                </button>
                <button
                    type="button"
                    role="tab"
                    class="panel__mode"
                    :class="{ 'is-active': ui.mode === 'inspect' }"
                    :aria-selected="ui.mode === 'inspect'"
                    @click="ui.inspect(ui.inspectTab)"
                >
                    Edit
                    <small v-if="props.selectionLabel">{{ props.selectionLabel }}</small>
                </button>
            </div>

            <div class="panel__body">
                <slot v-if="ui.mode === 'library'" name="library" />
                <slot v-else name="inspect" />
            </div>
        </template>

        <div
            v-if="!ui.panelCollapsed"
            class="panel__resize"
            role="separator"
            aria-orientation="vertical"
            aria-label="Resize the panel"
            tabindex="0"
            :aria-valuenow="ui.panelWidth"
            aria-valuemin="260"
            aria-valuemax="480"
            @pointerdown="onResizeStart"
            @pointermove="onResizeMove"
            @pointerup="onResizeEnd"
            @pointercancel="onResizeEnd"
            @keydown="onResizeKey"
        />
    </aside>
</template>

<style scoped>
.panel {
    position: relative;
    display: flex;
    flex-direction: column;
    flex: 0 0 auto;
    min-height: 0;
    border-right: 1px solid var(--builder-border);
    background: var(--builder-surface);
}

.panel.is-collapsed {
    width: 22px;
}

.panel__modes {
    display: flex;
    gap: 2px;
    padding: 8px 8px 0;
}

.panel__mode {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid var(--builder-border);
    border-bottom: 0;
    border-radius: 5px 5px 0 0;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.panel__mode.is-active {
    background: color-mix(in srgb, var(--builder-accent) 22%, transparent);
    border-color: var(--builder-accent);
}

.panel__mode small {
    display: block;
    font-size: 10px;
    opacity: 0.7;
    text-transform: capitalize;
}

.panel__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 10px 12px 16px;
    border-top: 1px solid var(--builder-border);
    margin-top: -1px;
}

.panel__collapse {
    position: absolute;
    top: 8px;
    right: 4px;
    z-index: 2;
    width: 16px;
    height: 20px;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: transparent;
    color: inherit;
    opacity: 0.6;
    font: inherit;
    cursor: pointer;
}

.panel.is-collapsed .panel__collapse {
    right: 3px;
}

.panel__collapse:hover {
    opacity: 1;
}

/* A grab strip wide enough to hit without being wide enough to notice. */
.panel__resize {
    position: absolute;
    top: 0;
    right: -3px;
    bottom: 0;
    width: 6px;
    cursor: col-resize;
    touch-action: none;
}

.panel__resize:focus-visible {
    outline: 2px solid var(--builder-accent);
}
</style>
