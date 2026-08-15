<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

/**
 * The actions a node offers, in one component so the canvas right-click
 * and the navigator's row menu cannot drift apart. Every canvas action
 * exists in the navigator too — that is the accessibility promise, and it
 * only holds while there is one list.
 */

export interface NodeAction {
    key: string
    label: string
    enabled: boolean
    hint?: string
}

const props = defineProps<{
    /** Viewport position to open at, or null to render inline (navigator). */
    at: { x: number; y: number } | null
    actions: NodeAction[]
    label: string
}>()

defineEmits<{ pick: [key: string]; close: [] }>()

const root = ref<HTMLElement | null>(null)

/**
 * Where the menu actually ends up, once it has been measured.
 *
 * A menu opened near the bottom or right edge would otherwise render
 * partly off-screen — and a `position: fixed` element that hangs off the
 * viewport cannot be scrolled to, so its lower entries become unreachable
 * rather than merely awkward. Measured after mount because the height
 * depends on how many actions this node offers.
 */
const placed = ref<{ top: number; left: number } | null>(null)

const MARGIN = 8

function place() {
    if (!props.at || !root.value) {
        placed.value = null

        return
    }

    const box = root.value.getBoundingClientRect()

    placed.value = {
        top: Math.max(MARGIN, Math.min(props.at.y, window.innerHeight - box.height - MARGIN)),
        left: Math.max(MARGIN, Math.min(props.at.x, window.innerWidth - box.width - MARGIN)),
    }
}

onMounted(place)
watch(() => props.at, place)

const style = computed(() => {
    if (!props.at) {
        return undefined
    }

    const at = placed.value ?? { top: props.at.y, left: props.at.x }

    return { top: `${at.top}px`, left: `${at.left}px` }
})
</script>

<template>
    <div
        ref="root"
        class="nodemenu"
        :class="{ 'is-floating': at !== null }"
        :style="style"
        role="menu"
        :aria-label="`${label} actions`"
    >
        <button
            v-for="action in actions"
            :key="action.key"
            type="button"
            role="menuitem"
            :disabled="!action.enabled"
            :title="action.hint"
            @click="$emit('pick', action.key)"
        >
            {{ action.label }}
        </button>
    </div>
</template>

<style scoped>
.nodemenu {
    display: flex;
    flex-direction: column;
    min-width: 150px;
    padding: 3px;
    border: 1px solid var(--builder-border);
    border-radius: 6px;
    background: var(--builder-surface);
}

.nodemenu.is-floating {
    position: fixed;
    z-index: 80;
    box-shadow: 0 6px 20px rgb(0 0 0 / 45%);
}

.nodemenu button {
    padding: 5px 10px;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}

.nodemenu button:hover:not(:disabled) {
    background: color-mix(in srgb, var(--builder-accent) 25%, transparent);
}

.nodemenu button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
