<script setup lang="ts">
/**
 * Checks & History: the accessibility checker and the revision browser.
 *
 * Both are pull surfaces — nothing runs until the editor asks, because a
 * check on every keystroke would teach people to ignore it and a history
 * fetch on open is wasted on the common editing session.
 */

const props = defineProps<{
    a11yFindings: { code: string; severity: string; nodeId: string | null; message: string }[] | null
    a11yRunning: boolean
    performance: { metrics: Record<string, number>; notes: { code: string; message: string }[] } | null
    performanceRunning: boolean
    revisions: { id: string; kind: string; label: string | null; author: string | null; createdAt: string }[] | null
    revisionsLoading: boolean
    canRestore: boolean
}>()

const emit = defineEmits<{
    runA11y: []
    runPerformance: []
    loadRevisions: []
    previewRevision: [id: string]
    restoreRevision: [id: string]
    selectNode: [id: string]
}>()

function kb(bytes: number): string {
    return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

function onFinding(finding: { nodeId: string | null }) {
    if (finding.nodeId !== null) {
        emit('selectNode', finding.nodeId)
    }
}

function formatWhen(iso: string): string {
    return new Date(iso).toLocaleString()
}
</script>

<template>
    <section class="tools">
        <h2 class="tools__heading">Checks</h2>

        <button type="button" :disabled="a11yRunning" @click="$emit('runA11y')">
            {{ a11yRunning ? 'Checking…' : 'Check accessibility' }}
        </button>

        <p v-if="a11yFindings !== null && a11yFindings.length === 0" class="tools__ok" role="status">
            No accessibility findings.
        </p>

        <ul v-if="a11yFindings !== null && a11yFindings.length > 0" class="tools__findings">
            <li
                v-for="(finding, i) in a11yFindings"
                :key="i"
                class="tools__finding"
                :data-severity="finding.severity"
            >
                <button
                    type="button"
                    class="tools__finding-body"
                    :disabled="finding.nodeId === null"
                    :title="finding.nodeId !== null ? 'Select this block' : undefined"
                    @click="onFinding(finding)"
                >
                    <span class="tools__code">{{ finding.severity }} · {{ finding.code }}</span>
                    {{ finding.message }}
                </button>
            </li>
        </ul>

        <button type="button" :disabled="performanceRunning" @click="$emit('runPerformance')">
            {{ performanceRunning ? 'Measuring…' : 'Measure performance' }}
        </button>

        <dl v-if="performance !== null" class="tools__metrics">
            <div><dt>Page weight</dt><dd>{{ kb(performance.metrics.gzippedBytes ?? 0) }} gzipped</dd></div>
            <div><dt>Blocks</dt><dd>{{ performance.metrics.blockCount ?? 0 }} in {{ performance.metrics.sectionCount ?? 0 }} sections</dd></div>
            <div><dt>Images</dt><dd>{{ performance.metrics.imageCount ?? 0 }}</dd></div>
        </dl>

        <ul v-if="performance !== null && performance.notes.length > 0" class="tools__findings">
            <li v-for="(note, i) in performance.notes" :key="i" class="tools__finding" data-severity="warning">
                <span class="tools__finding-body">
                    <span class="tools__code">{{ note.code }}</span>
                    {{ note.message }}
                </span>
            </li>
        </ul>

        <p v-if="performance !== null && performance.notes.length === 0" class="tools__ok" role="status">
            Within every budget.
        </p>

        <h2 class="tools__heading">History</h2>

        <button type="button" :disabled="revisionsLoading" @click="$emit('loadRevisions')">
            {{ revisionsLoading ? 'Loading…' : revisions === null ? 'Load history' : 'Refresh history' }}
        </button>

        <p v-if="revisions !== null && revisions.length === 0" class="tools__ok">No revisions yet.</p>

        <ul v-if="revisions !== null && revisions.length > 0" class="tools__revisions">
            <li v-for="revision in revisions" :key="revision.id" class="tools__revision">
                <div class="tools__revision-meta">
                    <span class="tools__kind" :data-kind="revision.kind">{{ revision.kind }}</span>
                    <span>{{ revision.label ?? formatWhen(revision.createdAt) }}</span>
                    <span v-if="revision.author" class="tools__author">{{ revision.author }}</span>
                </div>
                <div class="tools__revision-actions">
                    <button type="button" @click="$emit('previewRevision', revision.id)">Preview</button>
                    <button
                        type="button"
                        :disabled="!props.canRestore"
                        :title="props.canRestore ? undefined : 'Restoring needs the edit lock'"
                        @click="$emit('restoreRevision', revision.id)"
                    >
                        Restore
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.tools {
    padding: 12px;
    border-top: 1px solid var(--builder-border);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.tools__heading {
    margin: 0;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.7;
}

button {
    padding: 4px 10px;
    border: 1px solid var(--builder-border);
    border-radius: 4px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
    text-align: left;
}

button:disabled {
    opacity: 0.5;
    cursor: default;
}

.tools__ok {
    margin: 0;
    font-size: 12px;
    opacity: 0.7;
}

.tools__findings,
.tools__revisions {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tools__finding-body {
    width: 100%;
    font-size: 12px;
    line-height: 1.45;
}

.tools__finding[data-severity='error'] .tools__code {
    color: #ff8a8a;
}

.tools__finding[data-severity='warning'] .tools__code {
    color: #f0b45c;
}

.tools__code {
    display: block;
    font-size: 11px;
    font-weight: 600;
}

.tools__metrics {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 12px;
}

.tools__metrics div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}

.tools__metrics dt {
    opacity: 0.7;
}

.tools__metrics dd {
    margin: 0;
}

.tools__revision {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
}

.tools__revision-meta {
    display: flex;
    gap: 6px;
    align-items: baseline;
    flex-wrap: wrap;
}

.tools__kind {
    padding: 0 6px;
    border-radius: 999px;
    font-size: 10px;
    background: #3a3f4d;
}

.tools__kind[data-kind='publish'] {
    background: #1d4a2a;
}

.tools__author {
    opacity: 0.6;
}

.tools__revision-actions {
    display: flex;
    gap: 6px;
}
</style>
