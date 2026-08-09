import type { BlockDocument, BootstrapPayload, PatchOperation } from './document/types'

/**
 * The builder's server calls.
 *
 * Session-authenticated on the web group, so every write carries the panel's
 * CSRF token — read from the meta tag the shell renders. No bearer token is
 * minted for the builder: a second credential would need its own revocation
 * story for no gain, since the builder is only ever open inside an
 * authenticated admin session.
 */

export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message)
    }
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(init.headers ?? {}),
        },
    })

    if (!response.ok) {
        // The server's refusal message is written for a person (which
        // permission, which block) — surfacing it beats a generic failure.
        const body = await response.json().catch(() => ({}) as { message?: string })
        throw new ApiError(body.message ?? `Request failed (${response.status})`, response.status)
    }

    return (await response.json()) as T
}

export interface PatchResult {
    document: BlockDocument
    updated_at: string | null
}

export interface FragmentResult {
    node: string
    html: string
}

export function createApi(pageId: string, base = '/pages-builder') {
    return {
        bootstrap: (): Promise<BootstrapPayload> => request(`${base}/${pageId}`),

        patch: (operations: PatchOperation[]): Promise<PatchResult> =>
            request(`${base}/${pageId}`, {
                method: 'PATCH',
                body: JSON.stringify({ operations }),
            }),

        fragment: (node: string, blocks: BlockDocument): Promise<FragmentResult> =>
            request(`${base}/${pageId}/fragment`, {
                method: 'POST',
                body: JSON.stringify({ node, document: blocks }),
            }),

        canvasUrl: (): string => `${base}/${pageId}/canvas`,

        publish: (): Promise<{ status: string; published_at: string | null; url: string | null }> =>
            request(`${base}/${pageId}/publish`, { method: 'POST' }),

        patterns: (): Promise<{ patterns: { id: string; name: string; kind: string }[] }> =>
            request(`${base}/patterns`),

        savePattern: (name: string, kind: string, node: unknown): Promise<{ id: string }> =>
            request(`${base}/patterns`, { method: 'POST', body: JSON.stringify({ name, kind, node }) }),

        patternInstance: (id: string): Promise<{ kind: string; node: Record<string, unknown> }> =>
            request(`${base}/patterns/${id}/instance`),

        heartbeat: (): Promise<{ held: boolean }> =>
            request(`${base}/${pageId}/heartbeat`, { method: 'POST' }),

        takeOver: (): Promise<{ lock: { mine: boolean } }> =>
            request(`${base}/${pageId}/take-over`, { method: 'POST' }),

        /**
         * Best-effort goodbye on tab close. keepalive lets the request
         * outlive the page; a lost one only means the lock expires by TTL
         * instead of immediately.
         */
        release: (): void => {
            void fetch(`${base}/${pageId}/release`, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            }).catch(() => undefined)
        },
    }
}

export type BuilderApi = ReturnType<typeof createApi>
