/**
 * The canvas bridge: the only script the builder injects into the rendered
 * page (docs/magna-pages/03-BUILDER.md §8).
 *
 * It runs inside the iframe, next to the real published markup, so it stays
 * deliberately small and does exactly four things: report where nodes are,
 * forward clicks and hovers, swap one node's HTML when the server sends a
 * fresh fragment, and patch CSS variables for the instant style path.
 *
 * It never edits the document. The document lives in the parent's store and
 * is written only through the authorized patch endpoint — a bridge that
 * could mutate content would be a second, unauthorized write path into the
 * same data.
 *
 * Both directions pin the origin: the parent's origin is captured at
 * handshake time and every later message is checked against it, so another
 * frame cannot drive the canvas or read what it reports.
 */
;(function () {
    'use strict'

    var PROTOCOL = 1
    var parentOrigin = null
    var endGapTarget = null

    function nodes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-magna-node]'))
    }

    function rectFor(element) {
        var rect = element.getBoundingClientRect()

        return {
            node: element.getAttribute('data-magna-node'),
            kind: element.getAttribute('data-magna-kind'),
            top: rect.top + window.scrollY,
            left: rect.left + window.scrollX,
            width: rect.width,
            height: rect.height,
        }
    }

    function send(type, payload) {
        if (parentOrigin === null) {
            return
        }

        window.parent.postMessage(
            Object.assign({ magna: PROTOCOL, type: type }, payload || {}),
            parentOrigin,
        )
    }

    /*
     * Rects are reported at most once per frame.
     *
     * Measuring every node forces layout, and the callers are exactly the
     * things that fire in bursts: a ResizeObserver during a reflow, a
     * window resize, a fragment swap. Unbatched, a 300-node document
     * measured itself dozens of times for one visual change and posted the
     * whole set each time. Coalescing costs at most a frame of staleness,
     * which is the same frame the browser was going to paint anyway.
     */
    var rectsQueued = false

    var nextFrame =
        window.requestAnimationFrame
            ? window.requestAnimationFrame.bind(window)
            : function (callback) {
                  return window.setTimeout(callback, 16)
              }

    function reportRects() {
        if (rectsQueued) {
            return
        }

        rectsQueued = true
        nextFrame(function () {
            rectsQueued = false
            send('rects', { rects: nodes().map(rectFor), height: document.body.scrollHeight })
        })
    }

    function nodeIdFrom(target) {
        var element = target
        while (element && element !== document.body) {
            if (element.hasAttribute && element.hasAttribute('data-magna-node')) {
                return element.getAttribute('data-magna-node')
            }
            element = element.parentElement
        }

        return null
    }

    function findNode(id) {
        return document.querySelector('[data-magna-node="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]')
    }

    /** The node's own text, ignoring anything its descendants contribute. */
    function ownText(element) {
        var text = ''
        for (var i = 0; i < element.childNodes.length; i++) {
            if (element.childNodes[i].nodeType === 3) {
                text += element.childNodes[i].nodeValue
            }
        }

        return text.replace(/\s+/g, '')
    }

    /*
     * The element whose text IS the field's value.
     *
     * A block's MARKED element is usually a wrapper — the div carrying its
     * alignment and style classes — with the words an element or two below
     * it. Making the wrapper editable would flatten that structure into a
     * string, so instead descend while the path stays unambiguous: exactly
     * one element child, and no text of the parent's own to lose.
     *
     * Where the descent hits a fork — two element children, or text beside
     * an element — there is no single field being typed over, and null says
     * so. That is the case the inspector exists for.
     */
    function textElementOf(element) {
        var current = element
        for (var depth = 0; depth < 5; depth++) {
            if (current.children.length === 0) {
                return current
            }
            if (current.children.length > 1 || ownText(current) !== '') {
                return null
            }
            current = current.children[0]
        }

        return null
    }

    window.addEventListener('message', function (event) {
        var data = event.data
        if (!data || data.magna !== PROTOCOL) {
            return
        }

        // The handshake is the only message accepted from an unknown origin,
        // and it is what fixes the origin for everything afterwards.
        if (data.type === 'hello') {
            parentOrigin = event.origin
            send('ready', { nodes: nodes().length })
            reportRects()

            return
        }

        if (event.origin !== parentOrigin) {
            return
        }

        /*
         * Rich editing happens in the PARENT, over an overlay — the frame
         * must stay the production render. Two things the parent cannot
         * work out on its own: what the text looks like here, and getting
         * the original out of the way so the overlay is not drawn over it.
         *
         * The mask is an inline style set and then removed, in builder mode
         * only, exactly like the contenteditable attribute the plain path
         * already sets. Nothing is added to the DOM and nothing survives
         * the edit.
         */
        if (data.type === 'measure' && typeof data.node === 'string') {
            var measured = findNode(data.node)
            // The words carry the typography, not the wrapper around them.
            measured = measured ? textElementOf(measured) || measured : null
            if (measured) {
                var computed = window.getComputedStyle(measured)
                var copy = {}
                var properties = [
                    'font-family', 'font-size', 'font-weight', 'font-style',
                    'line-height', 'letter-spacing', 'text-align', 'text-transform',
                    'color', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                ]
                for (var i = 0; i < properties.length; i++) {
                    copy[properties[i]] = computed.getPropertyValue(properties[i])
                }

                send('measured', { node: data.node, styles: copy })
            }

            return
        }

        /*
         * Reserve room between the last document section and whatever the
         * theme renders after it (its footer), so the editor's add-here
         * affordance can sit IN the page flow the way Elementor's does —
         * pushing the footer down rather than covering it. An inline style
         * set and moved by the parent, builder mode only: the same category
         * of change as mask and contenteditable, and nothing of it ships.
         */
        if (data.type === 'endgap') {
            if (endGapTarget && endGapTarget.getAttribute('data-magna-node') !== data.node) {
                endGapTarget.style.removeProperty('margin-bottom')
                if (endGapTarget.getAttribute('style') === '') {
                    endGapTarget.removeAttribute('style')
                }
                endGapTarget = null
            }

            if (typeof data.node === 'string') {
                var gapEl = findNode(data.node)
                if (gapEl && gapEl.style.marginBottom !== data.size + 'px') {
                    gapEl.style.setProperty('margin-bottom', data.size + 'px')
                    endGapTarget = gapEl
                }
            }

            return
        }

        if (data.type === 'mask' && typeof data.node === 'string') {
            var masked = findNode(data.node)
            if (masked) {
                if (data.on === true) {
                    masked.style.setProperty('visibility', 'hidden')
                } else {
                    masked.style.removeProperty('visibility')
                    if (masked.getAttribute('style') === '') {
                        masked.removeAttribute('style')
                    }
                }
            }

            return
        }

        if (data.type === 'fragment' && typeof data.node === 'string' && typeof data.html === 'string') {
            var target = findNode(data.node)
            if (target) {
                target.outerHTML = data.html
                reportRects()
            }

            return
        }

        /*
         * Colour-scheme preview.
         *
         * An attribute set and removed on the root, in builder mode only —
         * the same category of change as the mask and the contenteditable
         * attribute, and nothing of it ships. The page's own stylesheet
         * already carries both readings, so this only chooses which one the
         * canvas is showing; no re-render is needed and none happens.
         */
        if (data.type === 'scheme') {
            if (data.scheme === 'light' || data.scheme === 'dark') {
                document.documentElement.setAttribute('data-theme', data.scheme)
            } else {
                document.documentElement.removeAttribute('data-theme')
            }

            return
        }

        if (data.type === 'tokens' && data.tokens) {
            // The instant path for style edits: set the CSS variable and the
            // page restyles without a server round trip.
            Object.keys(data.tokens).forEach(function (name) {
                document.documentElement.style.setProperty(name, String(data.tokens[name]))
            })
            reportRects()

            return
        }

        if (data.type === 'rects') {
            reportRects()

            return
        }

        /*
         * Inline text editing. The parent decides WHETHER a node is
         * editable (it knows the block schema and the actor's permissions);
         * this side only turns the marked element into a plain-text editor
         * and reports what was typed. textContent in, textContent out —
         * markup can neither enter nor leave through this path.
         */
        if (data.type === 'editable' && typeof data.node === 'string') {
            if (data.editable) {
                /*
                 * A double-click is three events: click, click, dblclick.
                 * The second click already opened the editor by the time
                 * the dblclick reached the parent and came back, so the
                 * stronger intent arrives LAST and would otherwise be
                 * dropped as "already editing". Honour it instead.
                 */
                if (inlineEditing !== null && inlineEditing.node === data.node) {
                    if (data.selectAll === true) {
                        selectAllIn(inlineEditing.element)
                    }

                    return
                }

                startInlineEdit(data.node, data.selectAll === true)
            } else if (inlineEditing !== null && inlineEditing.node === data.node) {
                inlineEditing.element.blur()
            }
        }
    })

    var inlineEditing = null

    /*
     * Is this event happening INSIDE the open editor?
     *
     * The canvas normally swallows clicks — a click selects a node, it must
     * not follow a link the way a visitor's would. But inside an open text
     * editor those same clicks are how a person places a caret, selects a
     * word with a double-click, or a line with a triple. Swallowing them
     * there left the caret pinned wherever it started, which made typing
     * feel like appending to a field rather than editing a page.
     */
    function editingContains(target) {
        return inlineEditing !== null && target instanceof Node && inlineEditing.element.contains(target)
    }

    function startInlineEdit(id, selectAll) {
        var marked = findNode(id)
        if (!marked || inlineEditing !== null) {
            return
        }

        // Not the marked element itself: the one actually holding the words.
        var element = textElementOf(marked)
        if (element === null) {
            send('uneditable', { node: id, reason: 'markup' })

            return
        }

        inlineEditing = {
            element: element,
            original: element.textContent,
            node: id,
        }

        element.setAttribute('contenteditable', 'plaintext-only')
        // Some engines reject plaintext-only; fall back and rely on the
        // textContent read to strip anything pasted.
        if (element.contentEditable !== 'plaintext-only') {
            element.setAttribute('contenteditable', 'true')
        }
        element.focus()

        /*
         * Whose caret is it: a DOUBLE-click means "replace these words", so
         * it selects them; a second single click means "put the cursor
         * here", so it leaves the caret the click already placed. Selecting
         * everything on a plain click would put an editor one keystroke
         * away from wiping a paragraph they meant to amend.
         */
        if (selectAll) {
            selectAllIn(element)
        }

        element.addEventListener('blur', finishInlineEdit)
        element.addEventListener('keydown', inlineEditKeys)
    }

    function selectAllIn(element) {
        var selection = window.getSelection()
        if (!selection) {
            return
        }

        var range = document.createRange()
        range.selectNodeContents(element)
        selection.removeAllRanges()
        selection.addRange(range)
    }

    function inlineEditKeys(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault()
            event.target.blur()
        }
        if (event.key === 'Escape') {
            if (inlineEditing) {
                inlineEditing.element.textContent = inlineEditing.original
            }
            event.target.blur()
        }
        // Keystrokes inside the editor must not become canvas shortcuts.
        event.stopPropagation()
    }

    function finishInlineEdit() {
        if (inlineEditing === null) {
            return
        }

        var element = inlineEditing.element
        var text = element.textContent || ''
        var changed = text !== inlineEditing.original
        var node = inlineEditing.node

        element.removeAttribute('contenteditable')
        element.removeEventListener('blur', finishInlineEdit)
        element.removeEventListener('keydown', inlineEditKeys)
        inlineEditing = null

        if (changed) {
            send('textCommit', { node: node, text: text })
        }
    }

    document.addEventListener(
        'dblclick',
        function (event) {
            // Inside the editor a double-click selects a word. That is the
            // browser's job and it does it better than we could.
            if (editingContains(event.target)) {
                return
            }

            var id = nodeIdFrom(event.target)
            if (id !== null) {
                event.preventDefault()
                send('editRequest', { node: id })
            }
        },
        true,
    )

    /*
     * The canvas is an EDITING surface, so nothing in it may act the way it
     * would for a visitor — and that is true of the whole canvas, not only
     * of the parts the builder marked.
     *
     * This used to prevent the default only for events landing inside a
     * marked node, and return early otherwise. Everything else kept native
     * behaviour: the header, the footer, and all theme chrome are rendered
     * but unmarked, so a click on a header link navigated the iframe away
     * from the page being edited and left the builder pointing at nothing.
     *
     * Containment belongs at the document, above the question of what was
     * clicked. Selection is the separate question, asked afterwards and
     * only when there is a node to select.
     */
    function containInCanvas(event) {
        if (editingContains(event.target)) {
            return false
        }

        event.preventDefault()
        event.stopPropagation()

        return true
    }

    document.addEventListener(
        'click',
        function (event) {
            if (!containInCanvas(event)) {
                return
            }

            var id = nodeIdFrom(event.target)
            if (id !== null) {
                send('select', { node: id })
            }
        },
        true,
    )

    // A middle click or a link with target="_blank" opens a tab that is not
    // the canvas; the editor never asked to leave.
    document.addEventListener('auxclick', containInCanvas, true)

    // Submitting a form navigates the frame as surely as a link does.
    document.addEventListener('submit', containInCanvas, true)

    /*
     * Keyboard activation of a focusable control — Enter on a link, Space
     * on a button — navigates too, and a canvas that only guards the mouse
     * is a canvas that loses its editor to the Tab key. Typing is exempt:
     * the inline editor's own keys are handled where the editor lives.
     */
    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return
            }
            if (editingContains(event.target)) {
                return
            }

            var target = event.target
            if (target && target.closest && target.closest('a[href], button, input, select, textarea')) {
                event.preventDefault()
            }
        },
        true,
    )

    document.addEventListener(
        'mouseover',
        function (event) {
            send('hover', { node: nodeIdFrom(event.target) })
        },
        true,
    )

    document.addEventListener(
        'dblclick',
        function (event) {
            var id = nodeIdFrom(event.target)
            if (id !== null) {
                event.preventDefault()
                send('editrequest', { node: id })
            }
        },
        true,
    )

    /*
     * Pointer position in canvas coordinates. The parent draws the drag
     * overlay, but the pointer is inside this frame while a drag crosses it
     * — without forwarding, a drag would go blind the moment it entered the
     * canvas, which is the whole area it operates in.
     */
    document.addEventListener(
        'pointerdown',
        function (event) {
            // Dragging a selection across text is not dragging the block.
            if (editingContains(event.target)) {
                return
            }

            var id = nodeIdFrom(event.target)
            if (id !== null) {
                send('pointerdown', {
                    node: id,
                    x: event.clientX + window.scrollX,
                    y: event.clientY + window.scrollY,
                })
            }
        },
        true,
    )

    document.addEventListener(
        'pointermove',
        function (event) {
            send('pointermove', {
                x: event.clientX + window.scrollX,
                y: event.clientY + window.scrollY,
            })
        },
        true,
    )

    /*
     * Right-click on a node. The browser's own menu is suppressed only over
     * a node the editor can act on — elsewhere in the canvas (a link, an
     * image, empty page chrome) the ordinary menu is still the useful one.
     *
     * Reported in VIEWPORT coordinates, without the scroll offset: the
     * parent positions the menu against the frame on screen, not against
     * the document inside it.
     */
    document.addEventListener('contextmenu', function (event) {
        var id = nodeIdFrom(event.target)
        if (id === null) {
            return
        }

        event.preventDefault()
        send('contextmenu', { node: id, x: event.clientX, y: event.clientY })
    })

    document.addEventListener(
        'pointerup',
        function (event) {
            send('pointerup', {
                x: event.clientX + window.scrollX,
                y: event.clientY + window.scrollY,
            })
        },
        true,
    )

    window.addEventListener('resize', reportRects)
    window.addEventListener('scroll', function () {
        send('scroll', { scrollY: window.scrollY })
    })

    if (window.ResizeObserver) {
        new ResizeObserver(reportRects).observe(document.body)
    }

    // The parent may not be listening yet; announce and let the handshake
    // settle the origin. Nothing this script sends leaves the frame before
    // that handshake, so announcing late means a canvas that renders but
    // does not respond — no rects, so no selection outline and nothing for
    // a drag to aim at.
    //
    // `load` waits for every image, font and stylesheet, which on a real
    // page is seconds after the document is usable. So announce as soon as
    // the DOM is parsed AND again on load: the parent answers each one, and
    // a second handshake costs one message.
    function announce() {
        window.parent.postMessage({ magna: PROTOCOL, type: 'loaded' }, '*')
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', announce)
    } else {
        announce()
    }

    window.addEventListener('load', announce)
})()
