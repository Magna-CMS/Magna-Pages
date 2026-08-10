import { ApiError } from './api'
import type { PatchOperation } from './document/types'

/**
 * Offline resilience (03-BUILDER §2 resilience): the send queue and its
 * IndexedDB persistence.
 *
 * The load-bearing distinction lives in classifyFailure: a server REFUSAL
 * (it answered, and said no) must roll the edit back — retrying a refusal
 * just refuses again — while a NETWORK failure (it never answered) must
 * keep the edit and queue the operations, because the edit is not wrong,
 * the train is in a tunnel.
 *
 * Queued batches persist to IndexedDB per page, so a tab crash mid-tunnel
 * loses nothing past the last gesture. Replay is strictly in order; a
 * refusal during replay abandons the queue — the document on the server
 * has moved underneath (lock takeover, permission change) and stale
 * operations must not be forced over it.
 */

export interface QueuedBatch {
    operations: PatchOperation[]
    queuedAt: number
}

export function classifyFailure(error: unknown): 'refusal' | 'network' {
    // ApiError means the server responded with a status — a decision.
    // Anything else (fetch TypeError, abort, timeout) means no answer.
    return error instanceof ApiError ? 'refusal' : 'network'
}

const DB_NAME = 'magna-pages-builder'

const STORE = 'send-queues'

function openDb(): Promise<IDBDatabase | null> {
    return new Promise((resolve) => {
        if (typeof indexedDB === 'undefined') {
            resolve(null)

            return
        }

        const request = indexedDB.open(DB_NAME, 1)
        request.onupgradeneeded = () => request.result.createObjectStore(STORE)
        request.onsuccess = () => resolve(request.result)
        // A broken IndexedDB (private mode, quota) degrades to memory-only
        // queueing — worse crash recovery, still-working offline editing.
        request.onerror = () => resolve(null)
    })
}

export async function persistQueue(pageId: string, batches: QueuedBatch[]): Promise<void> {
    const db = await openDb()
    if (db === null) {
        return
    }

    await new Promise<void>((resolve) => {
        const tx = db.transaction(STORE, 'readwrite')
        if (batches.length === 0) {
            tx.objectStore(STORE).delete(pageId)
        } else {
            tx.objectStore(STORE).put(batches, pageId)
        }
        tx.oncomplete = () => resolve()
        tx.onerror = () => resolve()
    })
    db.close()
}

export async function loadQueue(pageId: string): Promise<QueuedBatch[]> {
    const db = await openDb()
    if (db === null) {
        return []
    }

    const batches = await new Promise<QueuedBatch[]>((resolve) => {
        const request = db.transaction(STORE, 'readonly').objectStore(STORE).get(pageId)
        request.onsuccess = () => resolve(Array.isArray(request.result) ? request.result : [])
        request.onerror = () => resolve([])
    })
    db.close()

    return batches
}
