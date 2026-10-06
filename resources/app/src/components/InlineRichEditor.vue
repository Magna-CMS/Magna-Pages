<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'

import type { Editor } from '@tiptap/core'

/**
 * Rich inline editing, drawn OVER the canvas rather than inside it.
 *
 * The frame is the production render, so a rich editor may not live in it:
 * ProseMirror decorates the element it owns, and a canvas whose DOM
 * differs from the shipped DOM is the exact drift the one-renderer
 * decision exists to prevent. So the editor is mounted in the parent, sat
 * on the node's rect, wearing the node's own computed typography — and the
 * node underneath is hidden for the duration.
 *
 * TipTap is loaded on FIRST rich edit, not with the bundle: most editing
 * sessions never touch a richtext field, and they should not pay ~40KB for
 * the ones that do.
 */

const props = defineProps<{
    /** Where the node sits, in the parent's coordinates. */
    rect: { top: number; left: number; width: number; height: number }
    /** The stored markup this field holds. */
    html: string
    /** The node's computed typography, so the overlay reads as the page. */
    styles: Record<string, string>
    canEdit: boolean
}>()

const emit = defineEmits<{
    commit: [html: string]
    cancel: []
}>()

const host = ref<HTMLElement | null>(null)
const editor = shallowRef<Editor | null>(null)
const ready = ref(false)
/** The document as tiptap first parsed it — the baseline commit() compares against. */
const mountedHtml = ref('')
const marks = ref({ bold: false, italic: false, link: false, bulletList: false })

function syncMarks() {
    const instance = editor.value
    if (!instance) {
        return
    }

    marks.value = {
        bold: instance.isActive('bold'),
        italic: instance.isActive('italic'),
        link: instance.isActive('link'),
        bulletList: instance.isActive('bulletList'),
    }
}

onMounted(async () => {
    const [{ Editor: TipTapEditor }, { default: StarterKit }, { default: Link }] = await Promise.all([
        import('@tiptap/core'),
        import('@tiptap/starter-kit'),
        import('@tiptap/extension-link'),
    ])

    if (!host.value) {
        return
    }

    editor.value = new TipTapEditor({
        element: host.value,
        // No headings: a heading is a BLOCK, not a text style. Offering
        // both is how documents end up with an <h2> inside a paragraph.
        extensions: [
            StarterKit.configure({ heading: false, codeBlock: false, blockquote: false }),
            Link.configure({ openOnClick: false }),
        ],
        content: props.html,
        editable: props.canEdit,
        onSelectionUpdate: syncMarks,
        onTransaction: syncMarks,
    })

    editor.value.commands.focus('end')
    // What tiptap made of the stored HTML, before the editor was touched.
    // Everything this component can emit is a round trip through tiptap's
    // schema, so comparing against the ORIGINAL html would report a change
    // on every open; comparing against the round trip reports one only when
    // the user actually typed.
    mountedHtml.value = editor.value.getHTML()
    ready.value = true
})

onBeforeUnmount(() => {
    editor.value?.destroy()
    editor.value = null
})

function run(command: 'bold' | 'italic' | 'bulletList' | 'clear') {
    const chain = editor.value?.chain().focus()
    if (!chain) {
        return
    }

    if (command === 'bold') {
        chain.toggleBold().run()
    } else if (command === 'italic') {
        chain.toggleItalic().run()
    } else if (command === 'bulletList') {
        chain.toggleBulletList().run()
    } else {
        chain.unsetAllMarks().clearNodes().run()
    }

    syncMarks()
}

function toggleLink() {
    const instance = editor.value
    if (!instance) {
        return
    }

    if (instance.isActive('link')) {
        instance.chain().focus().unsetLink().run()
        syncMarks()

        return
    }

    const href = window.prompt('Link address')
    if (href === null || href.trim() === '') {
        return
    }

    // Whatever is typed here is stored as content and passes the server's
    // richtext sanitizer on save, which is what decides if it survives.
    instance.chain().focus().setLink({ href: href.trim() }).run()
    syncMarks()
}

/**
 * Only emit when the document actually changed.
 *
 * commit() is wired to @blur.capture, so focusing a text block and clicking
 * away used to rewrite its body as tiptap's round trip of it. The schema here
 * is deliberately narrow — no headings, no code blocks, no blockquotes — while
 * the server sanitizer allows far more, so that round trip silently flattened
 * tables, definition lists, figures, details and every h1-h6 an author had
 * put in the stored HTML. Nobody asked for an edit; focus was enough.
 */
function commit() {
    const html = editor.value?.getHTML() ?? ''

    if (html === mountedHtml.value) {
        return
    }

    mountedHtml.value = html
    emit('commit', html)
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault()
        // Revert: the stored value is the truth, and the caller re-renders
        // the node from it.
        emit('cancel')

        return
    }

    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault()
        commit()
    }
}
</script>

<template>
    <div
        class="inline"
        :style="{
            top: `${rect.top}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
            minHeight: `${rect.height}px`,
        }"
        @keydown="onKeydown"
    >
        <div class="inline__toolbar" role="toolbar" aria-label="Text formatting">
            <button
                type="button"
                :class="{ 'is-on': marks.bold }"
                :disabled="!ready"
                title="Bold"
                aria-label="Bold"
                @mousedown.prevent="run('bold')"
            >
                B
            </button>
            <button
                type="button"
                :class="{ 'is-on': marks.italic }"
                :disabled="!ready"
                title="Italic"
                aria-label="Italic"
                @mousedown.prevent="run('italic')"
            >
                I
            </button>
            <button
                type="button"
                :class="{ 'is-on': marks.link }"
                :disabled="!ready"
                title="Link"
                aria-label="Link"
                @mousedown.prevent="toggleLink"
            >
                🔗
            </button>
            <button
                type="button"
                :class="{ 'is-on': marks.bulletList }"
                :disabled="!ready"
                title="Bulleted list"
                aria-label="Bulleted list"
                @mousedown.prevent="run('bulletList')"
            >
                •
            </button>
            <button
                type="button"
                :disabled="!ready"
                title="Clear formatting"
                aria-label="Clear formatting"
                @mousedown.prevent="run('clear')"
            >
                ✕
            </button>
            <span class="inline__spacer" />
            <button
                type="button"
                :disabled="!ready"
                title="Save (Ctrl-Enter)"
                @mousedown.prevent="commit"
            >
                Done
            </button>
        </div>

        <div ref="host" class="inline__surface" :style="styles" @blur.capture="commit" />
    </div>
</template>

<style scoped>
.inline {
    position: absolute;
    z-index: 5;
    background: #fff;
    outline: 2px solid var(--builder-accent);
}

.inline__toolbar {
    position: absolute;
    top: -28px;
    left: 0;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 2px 3px;
    border-radius: 4px;
    background: var(--builder-accent);
}

.inline__toolbar button {
    min-width: 22px;
    padding: 1px 5px;
    border: 0;
    border-radius: 3px;
    background: rgb(255 255 255 / 15%);
    color: #fff;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}

.inline__toolbar button.is-on {
    background: #fff;
    color: var(--builder-accent);
}

.inline__toolbar button:disabled {
    opacity: 0.5;
    cursor: default;
}

.inline__spacer {
    width: 8px;
}

/* The surface wears the node's own computed typography, so what is typed
   looks like what will ship. */
.inline__surface :deep(.ProseMirror) {
    outline: none;
    min-height: 1em;
}

.inline__surface :deep(p) {
    margin: 0;
}
</style>
