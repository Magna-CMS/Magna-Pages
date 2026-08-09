<script setup lang="ts">
defineProps<{
    title: string
    status: string
    publicUrl: string | null
    saving: boolean
    canUndo: boolean
    canRedo: boolean
    canDelete: boolean
    canPublish: boolean
}>()

defineEmits<{ undo: []; redo: []; remove: []; publish: [] }>()
</script>

<template>
    <header class="topbar">
        <h1 class="topbar__title">
            {{ title || 'Untitled page' }}
            <span class="topbar__status" :data-status="status">{{ status }}</span>
        </h1>

        <div class="topbar__actions">
            <button type="button" :disabled="!canUndo" @click="$emit('undo')">Undo</button>
            <button type="button" :disabled="!canRedo" @click="$emit('redo')">Redo</button>
            <button type="button" :disabled="!canDelete" @click="$emit('remove')">Delete</button>
            <span class="topbar__state" role="status">{{ saving ? 'Saving…' : 'Saved' }}</span>

            <a v-if="publicUrl" class="topbar__view" :href="publicUrl" target="_blank" rel="noopener">
                View page
            </a>

            <button
                type="button"
                class="topbar__publish"
                :disabled="!canPublish"
                :title="canPublish ? undefined : 'Needs the publish permission'"
                @click="$emit('publish')"
            >
                {{ status === 'published' ? 'Republish' : 'Publish' }}
            </button>
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

.topbar__status {
    margin-left: 8px;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 500;
    background: #3a3f4d;
}

.topbar__status[data-status='published'] {
    background: #1d4a2a;
    color: #b9f0c8;
}

.topbar__view {
    font-size: 12px;
    color: inherit;
    opacity: 0.8;
}

.topbar__publish {
    border-color: var(--builder-accent);
    background: var(--builder-accent);
    color: #fff;
}

.topbar__publish:disabled {
    opacity: 0.4;
}
</style>
