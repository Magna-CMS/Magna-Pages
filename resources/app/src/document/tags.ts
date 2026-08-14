/**
 * Inline dynamic tags — `{tag:vendor.name}` tokens inside a text value,
 * substituted at render through the same allowlisted sources a `$bind` uses.
 *
 * The token is plain text once written: the editor moves it, copies it and
 * deletes it like any other characters, and nothing in the document format
 * knows it is special. That is deliberate — a token needs no node type, no
 * schema change and no migration, and a value carrying one round-trips
 * through any editor that can hold a string.
 */

/** What a tag token looks like once written into a value. */
export function tagToken(handle: string): string {
    return `{tag:${handle}}`
}

export interface TagInsertion {
    /** The value with the token in it. */
    value: string
    /** Where the caret belongs afterwards: immediately past the token. */
    caret: number
}

/**
 * Puts a tag token where the caret is, rather than at the end.
 *
 * The original inserter appended, which is only ever right when the caret
 * happens to be at the end — so inserting a tag while editing the middle of
 * a sentence put it after the full stop, and the writer had to cut and paste
 * it back. Appending is now just the case where `at` is the length.
 *
 * A selection is replaced, which is what every editor does with a paste and
 * what makes "select the placeholder, insert the tag" work.
 *
 * `at` and `to` are clamped into the value rather than trusted. A caret
 * offset arrives from the DOM and can be stale by the time it is used — the
 * field may have been re-rendered under it — and a negative or past-the-end
 * index would otherwise slice in a place the writer never chose.
 */
export function insertTag(value: string, handle: string, at: number, to = at): TagInsertion {
    const token = tagToken(handle)

    const start = clamp(Math.min(at, to), value.length)
    const end = clamp(Math.max(at, to), value.length)

    return {
        value: value.slice(0, start) + token + value.slice(end),
        caret: start + token.length,
    }
}

function clamp(index: number, length: number): number {
    if (!Number.isFinite(index)) {
        return length
    }

    return Math.max(0, Math.min(Math.trunc(index), length))
}
