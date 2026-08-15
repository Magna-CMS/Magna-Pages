/**
 * Completing a `{tag:…}` token as it is typed.
 *
 * The picker beside the field already inserts a tag, and it is the wrong
 * shape for the writer who is mid-sentence: they have to stop, leave the
 * text, find the tag in a list of everything the site offers, and come back.
 * Typing `{tag:` and seeing the two that match is the same feature at the
 * speed people write at.
 *
 * Everything here is a pure function of (value, caret). No DOM, no store, no
 * editor — the field owner reads its own element and asks these questions,
 * which is what makes the awkward parts (a token half-typed in the middle of
 * a paragraph, a caret sitting inside an already-closed token) testable
 * without a browser.
 */

/** How the token opens. Kept in one place — the renderer's own pattern. */
const OPENING = '{tag:'

/** A handle is `vendor.name`: letters, digits, dot, dash, underscore. */
const HANDLE_CHARACTER = /[a-z0-9._-]/i

/** Enough to choose from; more is a list nobody reads. */
const MAX_MATCHES = 8

export interface OpenTagQuery {
    /** What has been typed after `{tag:`, possibly empty. */
    query: string
    /** Where the token starts, at the `{`. */
    from: number
    /** Where a completion should stop replacing — past `}` when one is there. */
    to: number
}

export interface TagMatch {
    handle: string
    label: string
}

/**
 * The token being typed at the caret, if there is one.
 *
 * Scans back from the caret over handle characters to an opening `{tag:`.
 * Anything else in the way — a space, a newline, a `}` that closed an earlier
 * token — means the caret is not inside one, and returning null is how the
 * field knows to close the popup rather than offer completions for text that
 * merely contains a brace.
 */
export function openTagQuery(value: string, caret: number): OpenTagQuery | null {
    const at = Math.max(0, Math.min(Math.trunc(caret), value.length))

    let index = at

    while (index > 0 && HANDLE_CHARACTER.test(value[index - 1] ?? '')) {
        index--
    }

    const from = index - OPENING.length

    if (from < 0 || value.slice(from, index) !== OPENING) {
        return null
    }

    return {
        query: value.slice(index, at),
        from,
        /*
         * A caret inside `{tag:si|}` is completing THAT token, so the closing
         * brace is part of what gets replaced — otherwise accepting a match
         * would leave `{tag:site.year}}` behind, which renders as a stray
         * brace on the published page.
         */
        to: value[at] === '}' ? at + 1 : at,
    }
}

/**
 * The tags worth offering for a query, best first.
 *
 * Ranked rather than merely filtered: somebody typing `si` means `site.year`
 * far more often than they mean `analysis.visits`, so a handle that STARTS
 * with the query outranks one that merely contains it, and a handle match
 * outranks a label match. Ties fall back to alphabetical, so the list does
 * not reshuffle between keystrokes for no visible reason.
 *
 * @param sources handle => human label
 */
export function matchTags(sources: Record<string, string>, query: string): TagMatch[] {
    const needle = query.trim().toLowerCase()

    const scored: { match: TagMatch; score: number }[] = []

    for (const [handle, label] of Object.entries(sources)) {
        const score = scoreTag(handle.toLowerCase(), label.toLowerCase(), needle)

        if (score > 0) {
            scored.push({ match: { handle, label }, score })
        }
    }

    scored.sort((a, b) => b.score - a.score || a.match.handle.localeCompare(b.match.handle))

    return scored.slice(0, MAX_MATCHES).map((entry) => entry.match)
}

/**
 * The value with the chosen tag written into it, and where the caret goes.
 *
 * Deliberately not sharing `insertTag`: that one inserts at a caret and this
 * one REPLACES the half-typed token, and folding the two together would mean
 * a function whose behaviour depended on whether its caller had detected a
 * token first.
 */
export function completeTag(
    value: string,
    handle: string,
    open: OpenTagQuery,
): { value: string; caret: number } {
    const token = `${OPENING}${handle}}`
    const from = Math.max(0, Math.min(open.from, value.length))
    const to = Math.max(from, Math.min(open.to, value.length))

    return {
        value: value.slice(0, from) + token + value.slice(to),
        caret: from + token.length,
    }
}

function scoreTag(handle: string, label: string, needle: string): number {
    if (needle === '') {
        // An empty query is the moment `{tag:` is typed: offer everything,
        // in alphabetical order, which the sort below arranges.
        return 1
    }

    if (handle.startsWith(needle)) {
        return 4
    }

    if (handle.includes(needle)) {
        return 3
    }

    if (label.startsWith(needle)) {
        return 2
    }

    return label.includes(needle) ? 1 : 0
}
