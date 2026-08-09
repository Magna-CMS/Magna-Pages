<script setup lang="ts">
defineProps<{
    title: string
    saving: boolean
    canUndo: boolean
    canRedo: boolean
    canDelete: boolean
}>()

defineEmits<{ undo: []; redo: []; remove: [] }>()
</script>

<template>
    <header class="topbar">
        <h1 class="topbar__title">{{ title || 'Untitled page' }}</h1>

        <div class="topbar__actions">
            <button type="button" :disabled="!canUndo" @click="$emit('undo')">Undo</button>
            <button type="button" :disabled="!canRedo" @click="$emit('redo')">Redo</button>
            <button type="button" :disabled="!canDelete" @click="$emit('remove')">Delete</button>
            <span class="topbar__state" role="status">{{ saving ? 'Saving…' : 'Saved' }}</span>
        </div>
    </header>
</template>

<style scoped>
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--builder-border);
}

.topbar__title {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
}

.topbar__actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

button {
    padding: 4px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

button:disabled {
    opacity: 0.4;
    cursor: default;
}

.topbar__state {
    font-size: 12px;
    opacity: 0.7;
}
</style>
