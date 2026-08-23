import type { BlockDocument } from './document/types'

/**
 * The parent half of the canvas bridge.
 *
 * Mirrors resources/js/builder-bridge.js. Origin is pinned in both
 * directions: the iframe is same-origin (the plugin serves it), so the
 * expected origin is simply this window's, and anything else is ignored
 * rather than trusted because it arrived on the right channel.
 */

const PROTOCOL = 1

export interface NodeRect {
    node: string
    kind: 'section' | 'column' | 'block'
    top: number
    left: number
    width: number
    height: number
}

export interface PointerPosition {
    x: number
    y: number
}

export interface BridgeHandlers {
    onRects?: (rects: NodeRect[], height: number) => void
    /**
     * `doc` names the entry the node lives in when it is NOT the one this
     * session opened — a header or footer. Null means the opened document.
     */
    onSelect?: (node: string, doc: string | null) => void
    onHover?: (node: string | null) => void
    onScroll?: (scrollY: number) => void
    onReady?: () => void
    onPointerDown?: (node: string, at: PointerPosition) => void
    onPointerMove?: (at: PointerPosition) => void
    onPointerUp?: (at: PointerPosition) => void
    /** Right-click on a node, in viewport coordinates of the frame. */
    onContextMenu?: (node: string, at: PointerPosition) => void
    /** A node's computed typography, for the rich-edit overlay to match. */
    onMeasured?: (node: string, styles: Record<string, string>) => void
    onEditRequest?: (node: string) => void
    /** A click landed in the header or footer, which is another document. */
    onChrome?: (chrome: {
        role: 'header' | 'footer'
        id: string | null
        title: string | null
        rect: { top: number; left: number; width: number; height: number }
    }) => void
    onTextCommit?: (node: string, text: string) => void
    onUneditable?: (node: string, reason: string) => void
}

export class CanvasBridge {
    private frame: HTMLIFrameElement | null = null

    private readonly origin = window.location.origin

    private readonly listener: (event: MessageEvent) => void

    constructor(private readonly handlers: BridgeHandlers) {
        this.listener = (event: MessageEvent) => this.receive(event)
        window.addEventListener('message', this.listener)
    }

    attach(frame: HTMLIFrameElement): void {
        this.frame = frame
    }

    destroy(): void {
        window.removeEventListener('message', this.listener)
        this.frame = null
    }

    /** Swap one node's markup after a fragment render. */
    applyFragment(node: string, html: string): void {
        this.post({ type: 'fragment', node, html })
    }

    /** The instant style path: set CSS variables without a round trip. */
    applyTokens(tokens: Record<string, string>): void {
        this.post({ type: 'tokens', tokens })
    }

    /**
     * Preview a colour scheme in the canvas.
     *
     * The page already carries every reading in one stylesheet, so this
     * chooses which one shows rather than asking for a different page.
     */
    setScheme(scheme: 'system' | 'light' | 'dark'): void {
        this.post({ type: 'scheme', scheme })
    }

    /**
     * Open (or close) the plain-text inline editor on a node's element.
     *
     * `selectAll` carries the gesture's intent through: a double-click
     * replaces the text, a click on an already-selected node types into it.
     */
    setEditable(node: string, editable: boolean, selectAll = false): void {
        this.post({ type: 'editable', node, editable, selectAll })
    }

    requestRects(): void {
        this.post({ type: 'rects' })
    }

    /** Ask the frame what a node's text looks like, so an overlay can match it. */
    measure(node: string): void {
        this.post({ type: 'measure', node })
    }

    /**
     * Hide (or restore) a node while the parent edits it over the top.
     * An inline style set and removed — the same category of change as the
     * contenteditable attribute the plain path already sets, and nothing
     * of it survives the edit.
     */
    mask(node: string, on: boolean): void {
        this.post({ type: 'mask', node, on })
    }

    /**
     * Reserve room after the given section (or none), so the add-here
     * affordance can sit in the page flow, pushing the theme's footer down
     * rather than covering it.
     */
    endGap(node: string | null, size: number): void {
        this.post({ type: 'endgap', node, size })
    }

    private post(message: Record<string, unknown>): void {
        this.frame?.contentWindow?.postMessage({ magna: PROTOCOL, ...message }, this.origin)
    }

    private receive(event: MessageEvent): void {
        if (event.origin !== this.origin) {
            return
        }

        const data = event.data as Record<string, unknown> | null
        if (!data || data.magna !== PROTOCOL) {
            return
        }

        switch (data.type) {
            case 'loaded':
                // The frame announced itself; the handshake fixes the origin
                // it will accept commands from.
                this.post({ type: 'hello' })
                break
            case 'ready':
                this.handlers.onReady?.()
                break
            case 'rects':
                this.handlers.onRects?.(data.rects as NodeRect[], Number(data.height ?? 0))
                break
            case 'measured':
                this.handlers.onMeasured?.(
                    String(data.node),
                    (data.styles ?? {}) as Record<string, string>,
                )
                break
            case 'contextmenu':
                this.handlers.onContextMenu?.(String(data.node), {
                    x: Number(data.x ?? 0),
                    y: Number(data.y ?? 0),
                })
                break
            case 'select':
                this.handlers.onSelect?.(
                    String(data.node),
                    typeof data.doc === 'string' && data.doc !== '' ? data.doc : null,
                )
                break
            case 'hover':
                this.handlers.onHover?.(data.node === null ? null : String(data.node))
                break
            case 'scroll':
                this.handlers.onScroll?.(Number(data.scrollY ?? 0))
                break
            case 'pointerdown':
                this.handlers.onPointerDown?.(String(data.node), pointerFrom(data))
                break
            case 'pointermove':
                this.handlers.onPointerMove?.(pointerFrom(data))
                break
            case 'pointerup':
                this.handlers.onPointerUp?.(pointerFrom(data))
                break
            case 'chrome':
                this.handlers.onChrome?.({
                    role: data.role === 'footer' ? 'footer' : 'header',
                    id: typeof data.id === 'string' && data.id !== '' ? data.id : null,
                    title: typeof data.title === 'string' && data.title !== '' ? data.title : null,
                    rect: data.rect as { top: number; left: number; width: number; height: number },
                })
                break

            case 'editRequest':
                this.handlers.onEditRequest?.(String(data.node))
                break
            case 'textCommit':
                this.handlers.onTextCommit?.(String(data.node), String(data.text ?? ''))
                break
            case 'uneditable':
                this.handlers.onUneditable?.(String(data.node), String(data.reason ?? ''))
                break
            default:
                break
        }
    }
}

function pointerFrom(data: Record<string, unknown>): PointerPosition {
    return { x: Number(data.x ?? 0), y: Number(data.y ?? 0) }
}

/** Fragment requests are debounced per node — a keystroke is not a render. */
export function debounceByKey<T extends unknown[]>(
    fn: (key: string, ...args: T) => void,
    wait: number,
): (key: string, ...args: T) => void {
    const timers = new Map<string, ReturnType<typeof setTimeout>>()

    return (key: string, ...args: T) => {
        const existing = timers.get(key)
        if (existing) {
            clearTimeout(existing)
        }
        timers.set(
            key,
            setTimeout(() => {
                timers.delete(key)
                fn(key, ...args)
            }, wait),
        )
    }
}

export type FragmentFetcher = (node: string, blocks: BlockDocument) => Promise<{ html: string }>
