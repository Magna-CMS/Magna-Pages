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
        }
    })

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

    // The parent may not be listening yet; announce once loaded and let the
    // handshake settle the origin.
    window.addEventListener('load', function () {
        window.parent.postMessage({ magna: PROTOCOL, type: 'loaded' }, '*')
    })
})()
