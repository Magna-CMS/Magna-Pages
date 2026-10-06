/**
 * Reading and writing a `json` field's value.
 *
 * A json field holds a real array or object — the items behind features,
 * stats, pricing, team, testimonials and logos. The inspector used to render
 * every field through one "string or number, else empty" reader, so a json
 * field whose value was an array showed an EMPTY single-line box over content
 * that existed, and the first keystroke replaced that array with a string.
 *
 * Both halves live here rather than in the component because this is the part
 * that can destroy an author's content, and a component in this app is not
 * reachable from the test environment.
 */

/** What the editor shows for a stored value. */
export function jsonFieldText(value: unknown): string {
    if (value === null || value === undefined) {
        return ''
    }

    // Already text — a document that stored its list as a JSON string, which
    // the render side still accepts. Shown as typed, not re-encoded.
    if (typeof value === 'string') {
        return value
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 2)
    }

    return String(value)
}

export type JsonFieldEdit =
    | { ok: true; value: unknown }
    | { ok: false; message: string }

/**
 * What an edit means, or why it was refused.
 *
 * Refusing is the point: the alternative is saving the half-typed text over
 * the list it replaced, which is exactly the failure this field had before.
 * An emptied box is a deliberate clear, not a parse failure, so it writes
 * null rather than an error.
 */
export function jsonFieldEdit(raw: string): JsonFieldEdit {
    if (raw.trim() === '') {
        return { ok: true, value: null }
    }

    try {
        return { ok: true, value: JSON.parse(raw) as unknown }
    } catch (error) {
        return {
            ok: false,
            message: error instanceof Error ? error.message : 'That is not valid JSON.',
        }
    }
}
