import type { PatchOperation } from './types'

/**
 * Applying and inverting builder patches on the client.
 *
 * The client applies patches optimistically so editing feels instant, then
 * the server applies the same batch authoritatively. That only holds
 * together if both sides agree on what an operation MEANS — so the two
 * rules a naive implementation gets wrong are mirrored here exactly as
 * PatchApplier.php has them:
 *
 *   - `add` at a list index INSERTS BEFORE, it does not overwrite
 *   - `remove` from a list CLOSES THE GAP, so later pointers in the same
 *     batch still address the node their author meant
 *
 * Every application also returns the inverse batch, which is what undo
 * replays. Inverses are computed from the document as it was BEFORE the
 * operation ran, because that is the only moment the displaced value still
 * exists.
 */

export class PatchError extends Error {}

interface Applied<T> {
    document: T
    inverse: PatchOperation[]
}

/** Decode a JSON Pointer into its segments (RFC 6901 escaping). */
export function pointerSegments(pointer: string): string[] {
    if (!pointer.startsWith('/')) {
        throw new PatchError(`Pointer must be absolute: ${pointer}`)
    }

    return pointer
        .slice(1)
        .split('/')
        .map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~'))
}

/** Encode segments back into a JSON Pointer. */
export function toPointer(segments: (string | number)[]): string {
    return segments
        .map((segment) => String(segment).replace(/~/g, '~0').replace(/\//g, '~1'))
        .map((segment) => `/${segment}`)
        .join('')
}

/**
 * Apply a batch to a document, returning a NEW document plus the inverse
 * batch. The input is never mutated: a rejected operation must leave the
 * store exactly as the user last saw it.
 */
export function applyPatch<T>(document: T, operations: PatchOperation[]): Applied<T> {
    let result = clone(document)
    const inverse: PatchOperation[] = []

    for (const operation of operations) {
        const step = applyOne(result, operation)
        result = step.document
        // Inverses undo in reverse order, so the newest goes first.
        inverse.unshift(...step.inverse)
    }

    return { document: result, inverse }
}

function applyOne<T>(document: T, operation: PatchOperation): Applied<T> {
    if (operation.op === 'move') {
        if (!operation.from) {
            throw new PatchError('A move operation needs a "from" pointer.')
        }

        const moved = readAt(document, pointerSegments(operation.from))
        const removed = write(document, pointerSegments(operation.from), 'remove', undefined)
        const added = write(removed.document, pointerSegments(operation.path), 'add', moved)

        return {
            document: added.document,
            inverse: [{ op: 'move', path: operation.from, from: operation.path }],
        }
    }

    return write(document, pointerSegments(operation.path), operation.op, operation.value)
}

function write<T>(node: T, segments: string[], op: string, value: unknown): Applied<T> {
    const [key, ...rest] = segments
    const container = node as unknown as Record<string, unknown> | unknown[]

    if (rest.length > 0) {
        const child = get(container, key)
        if (child === undefined || child === null || typeof child !== 'object') {
            throw new PatchError(`Patch path does not exist: /${segments.join('/')}`)
        }

        const step = write(child, rest, op, value)
        const next = shallowCopy(container)
        set(next, key, step.document)

        return {
            document: next as unknown as T,
            inverse: step.inverse.map((entry) => prefixPointers(entry, key)),
        }
    }

    const next = shallowCopy(container)
    const isList = Array.isArray(next)

    if (op === 'add') {
        if (isList) {
            const list = next as unknown[]
            const index = key === '-' ? list.length : Number(key)
            if (Number.isNaN(index) || index > list.length) {
                throw new PatchError(`Patch index out of range: ${key}`)
            }
            // Insert-before, matching the server.
            list.splice(index, 0, value)

            return {
                document: next as unknown as T,
                inverse: [{ op: 'remove', path: `/${index}` }],
            }
        }

        const existed = key in (next as Record<string, unknown>)
        const previous = get(next, key)
        set(next, key, value)

        return {
            document: next as unknown as T,
            inverse: existed
                ? [{ op: 'replace', path: `/${key}`, value: previous }]
                : [{ op: 'remove', path: `/${key}` }],
        }
    }

    if (op === 'replace') {
        if (get(next, key) === undefined && !(isList ? false : key in (next as Record<string, unknown>))) {
            throw new PatchError(`Patch path does not exist: /${segments.join('/')}`)
        }
        const previous = get(next, key)
        set(next, key, value)

        return {
            document: next as unknown as T,
            inverse: [{ op: 'replace', path: `/${key}`, value: previous }],
        }
    }

    if (op === 'remove') {
        const previous = get(next, key)
        if (previous === undefined) {
            throw new PatchError(`Patch path does not exist: /${segments.join('/')}`)
        }

        if (isList) {
            // Close the gap, matching the server.
            ;(next as unknown[]).splice(Number(key), 1)
        } else {
            delete (next as Record<string, unknown>)[key]
        }

        return {
            document: next as unknown as T,
            inverse: [{ op: 'add', path: `/${key}`, value: previous }],
        }
    }

    throw new PatchError(`Unsupported patch operation: ${op}`)
}

/** Re-root an inverse operation produced one level down. */
function prefixPointers(operation: PatchOperation, key: string): PatchOperation {
    const prefix = `/${key.replace(/~/g, '~0').replace(/\//g, '~1')}`
    const next: PatchOperation = { ...operation, path: prefix + operation.path }
    if (operation.from) {
        next.from = prefix + operation.from
    }

    return next
}

function readAt(node: unknown, segments: string[]): unknown {
    let cursor: unknown = node

    for (const segment of segments) {
        if (cursor === null || typeof cursor !== 'object') {
            throw new PatchError(`Patch source path does not exist: /${segments.join('/')}`)
        }
        cursor = get(cursor as Record<string, unknown> | unknown[], segment)
    }

    if (cursor === undefined) {
        throw new PatchError(`Patch source path does not exist: /${segments.join('/')}`)
    }

    return cursor
}

function get(container: Record<string, unknown> | unknown[], key: string): unknown {
    return Array.isArray(container)
        ? container[Number(key)]
        : container[key]
}

function set(container: Record<string, unknown> | unknown[], key: string, value: unknown): void {
    if (Array.isArray(container)) {
        container[Number(key)] = value
    } else {
        container[key] = value
    }
}

function shallowCopy<T>(container: T): T {
    return (Array.isArray(container) ? [...container] : { ...(container as object) }) as T
}

function clone<T>(value: T): T {
    // JSON round-trip, NOT structuredClone: the store hands us Vue reactive
    // proxies, which structuredClone refuses to clone — a failure no unit
    // test saw (plain objects) and the first real browser session hit on
    // its first edit. Documents are JSON by definition (they arrive from
    // and return to a JSON API), so the round-trip is lossless here.
    return JSON.parse(JSON.stringify(value)) as T
}
