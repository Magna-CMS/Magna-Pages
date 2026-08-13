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

    function reportRects() {
        send('rects', { rects: nodes().map(rectFor), height: document.body.scrollHeight })
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

        if (data.type === 'fragment' && typeof data.node === 'string' && typeof data.html === 'string') {
            var target = findNode(data.node)
            if (target) {
                target.outerHTML = data.html
                reportRects()
            }

            return
        }

        /*
         * Inline editing. The parent decides WHICH node is editable (it
         * knows the block's field schema and the actor's permissions); this
         * side only turns the real element into a text input and reports
         * what was typed.
         *
         * Refused when the element contains child markup: this edits text,
         * and writing innerText back over a node with structure inside it
         * would silently destroy that structure. Rich text keeps using the
         * inspector until an editor that understands markup is mounted here.
         */
        if (data.type === 'editable' && typeof data.node === 'string') {
            var editing = findNode(data.node)
            if (!editing) {
                return
            }

            if (data.on === false) {
                editing.removeAttribute('contenteditable')

                return
            }

            if (editing.children.length > 0) {
                send('uneditable', { node: data.node, reason: 'markup' })

                return
            }

            editing.setAttribute('contenteditable', 'plaintext-only')
            editing.focus()

            editing.addEventListener('input', function () {
                send('text', { node: data.node, text: editing.innerText })
            })

            editing.addEventListener('blur', function () {
                editing.removeAttribute('contenteditable')
                send('textcommit', { node: data.node, text: editing.innerText })
            })

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
                startInlineEdit(data.node)
            } else if (inlineEditing !== null && inlineEditing.node === data.node) {
                inlineEditing.element.blur()
            }
        }
    })

    var inlineEditing = null

    function startInlineEdit(id) {
        var element = findNode(id)
        if (!element || inlineEditing !== null) {
            return
        }

        // An element with child ELEMENTS carries markup; editing it as text
        // would flatten that markup into a string. The inspector is the
        // editing path for those.
        if (element.children.length > 0) {
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

        var selection = window.getSelection()
        if (selection) {
            var range = document.createRange()
            range.selectNodeContents(element)
            selection.removeAllRanges()
            selection.addRange(range)
        }

        element.addEventListener('blur', finishInlineEdit)
        element.addEventListener('keydown', inlineEditKeys)
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
            var id = nodeIdFrom(event.target)
            if (id !== null) {
                event.preventDefault()
                send('editRequest', { node: id })
            }
        },
        true,
    )

    document.addEventListener(
        'click',
        function (event) {
            var id = nodeIdFrom(event.target)
            if (id === null) {
                return
            }

            // In the canvas a click selects; it must not follow links or
            // submit forms the way it would for a visitor.
            event.preventDefault()
            event.stopPropagation()
            send('select', { node: id })
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
