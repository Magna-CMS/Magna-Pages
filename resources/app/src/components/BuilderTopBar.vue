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
    canSavePattern: boolean
    canRequestPublish: boolean
    publishRequested: boolean
    pendingCount: number
}>()

defineEmits<{
    undo: []
    redo: []
    remove: []
    publish: []
    savePattern: []
    requestPublish: []
    exportLibrary: []
}>()
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
            <button type="button" :disabled="!canSavePattern" @click="$emit('savePattern')">
                Save as pattern
            </button>
            <button type="button" title="Download as a cloud-library asset file" @click="$emit('exportLibrary')">
                Export
            </button>
            <span
                class="topbar__state"
                :class="{ 'topbar__state--pending': pendingCount > 0 }"
                role="status"
            >
                {{
                    pendingCount > 0
                        ? `Offline — ${pendingCount} ${pendingCount === 1 ? 'change' : 'changes'} pending`
                        : saving
                          ? 'Saving…'
                          : 'Saved'
                }}
            </span>

            <a v-if="publicUrl" class="topbar__view" :href="publicUrl" target="_blank" rel="noopener">
                View page
            </a>

            <button
                v-if="canPublish"
                type="button"
                class="topbar__publish"
                @click="$emit('publish')"
            >
                {{ status === 'published' ? 'Republish' : 'Publish' }}
            </button>

            <!-- An editor without the publish permission asks instead. -->
            <button
                v-else
                type="button"
                class="topbar__publish"
                :disabled="!canRequestPublish || publishRequested"
                @click="$emit('requestPublish')"
            >
                {{ publishRequested ? 'Publish requested' : 'Request publish' }}
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

.topbar__state--pending {
    opacity: 1;
    color: #f0b45c;
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
