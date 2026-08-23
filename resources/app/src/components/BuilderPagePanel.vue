<script setup lang="ts">
import BuilderStyleControls from './BuilderStyleControls.vue'
import type { Breakpoint } from '../document/responsive'
import type { Capabilities, ChromeChoice, StyleControl } from '../document/types'
import { useUiStore } from '../stores/ui'

/**
 * The page itself: the ground it is drawn on, and the chrome around it.
 *
 * Its own panel rather than "what Edit shows when nothing is selected".
 * That earlier arrangement was true to the model — the page IS what is
 * selected when no node is — but it made the page's settings reachable
 * only by knowing to deselect first, and a surface you reach by accident
 * is a surface that does not exist.
 */

defineProps<{
    capabilities: Capabilities
    controls: StyleControl[]
    style: Record<string, unknown>
    breakpoint: Breakpoint
    chromeChoices: { header: ChromeChoice[]; footer: ChromeChoice[] }
    chromeUsed: { header: string; footer: string }
}>()

defineEmits<{
    setStyle: [key: string, value: string]
    setChrome: [role: 'header' | 'footer', id: string]
    editChrome: [id: string]
}>()

const ui = useUiStore()

const ROLES = ['header', 'footer'] as const
</script>

<template>
    <div class="page">
        <!--
            The device and scheme being previewed decide which reading a
            style edit writes, and that is not obvious from a toolbar at
            the top of the canvas. Said here, where the writing happens.
        -->
        <p v-if="ui.breakpoint !== 'desktop' || ui.scheme !== 'system'" class="page__scope">
            Editing the
            <strong>{{ ui.breakpoint }}</strong>
            <template v-if="ui.scheme !== 'system'"> · <strong>{{ ui.scheme }}</strong></template>
            view
        </p>

        <!-- No heading of its own: the style controls already group and
             label themselves, and a second "Background" above their own
             one is a label that labels a label. -->
        <section class="page__group">
            <BuilderStyleControls
                v-if="controls.length > 0"
                :controls="controls"
                :style="style"
                :can-edit="capabilities.style"
                :breakpoint="breakpoint"
                @set="(key: string, value: string) => $emit('setStyle', key, value)"
            />
        </section>

        <!--
            Which chrome this page uses.

            The empty option has to SAY "site default": choosing nothing is
            not "no header", and a blank option reads as an absence. A role
            with nothing published to choose between is offered as a way to
            make one instead, because an empty select teaches nothing.
        -->
        <section class="page__group">
            <h3 class="page__heading">Header &amp; footer</h3>

            <div v-for="role in ROLES" :key="role" class="page__field">
                <label :for="`page-${role}`">{{ role === 'header' ? 'Header' : 'Footer' }}</label>

                <template v-if="chromeChoices[role].length > 0">
                    <select
                        :id="`page-${role}`"
                        :value="chromeUsed[role]"
                        :disabled="!capabilities.style"
                        @change="$emit('setChrome', role, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Site default</option>
                        <!-- A CHOICE, not an absence: a landing page that
                             wants no header is saying something, and saying
                             it by clearing the field would be the same as
                             never having decided. -->
                        <option value="none">None — this page has no {{ role }}</option>
                        <!-- A draft is named and unselectable rather than
                             hidden: only a published part renders, and
                             hiding one tells an editor their work does not
                             exist. -->
                        <option
                            v-for="choice in chromeChoices[role]"
                            :key="choice.id"
                            :value="choice.id"
                            :disabled="!choice.published"
                        >
                            {{ choice.title }}{{ choice.published ? '' : ' (draft — publish to use)' }}
                        </option>
                    </select>

                    <!-- Editing the chrome means opening the part that IS
                         the chrome, so the builder says which one and takes
                         you there rather than describing where to look. -->
                    <button
                        v-if="chromeUsed[role] !== ''"
                        type="button"
                        class="page__link"
                        @click="$emit('editChrome', chromeUsed[role])"
                    >
                        Design this {{ role }}
                    </button>
                </template>

                <p v-else class="page__hint">
                    No {{ role }} designed yet. Make one from Pages, then choose it here.
                </p>
            </div>
        </section>

        <p v-if="!capabilities.style" class="page__locked">
            Page settings need the design permission.
        </p>
    </div>
</template>

<style scoped>
.page__scope {
    margin: 0 0 10px;
    padding: 5px 8px;
    border-radius: 5px;
    background: color-mix(in srgb, var(--builder-accent) 16%, transparent);
    font-size: 11px;
    text-transform: capitalize;
}

.page__group {
    margin-bottom: 14px;
}

.page__heading {
    margin: 0 0 6px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.6;
}

.page__field {
    margin-bottom: 8px;
}

.page__field label {
    display: block;
    margin-bottom: 3px;
    font-size: 12px;
}

.page__field select {
    width: 100%;
    padding: 5px 7px;
    border: 1px solid var(--builder-border);
    border-radius: 5px;
    background: #0f1117;
    color: inherit;
    font: inherit;
    font-size: 13px;
}

.page__link {
    margin-top: 4px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--builder-accent);
    font: inherit;
    font-size: 12px;
    text-decoration: underline;
    text-underline-offset: 2px;
    cursor: pointer;
}

.page__hint,
.page__locked {
    margin: 4px 0 0;
    font-size: 12px;
    opacity: 0.6;
}
</style>
