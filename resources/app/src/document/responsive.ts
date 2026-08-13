import type { PatchOperation } from './types'

/**
 * Reading and writing per-device style values.
 *
 * A value is either a scalar — the same at every width — or the sentinel
 * `{"$responsive": {base, tablet, mobile}}`, mirroring the `$bind`
 * convention the document already uses. The renderer decides what these
 * mean; this file only has to produce and read them the same way.
 *
 * Editing at a breakpoint writes ONLY that breakpoint, and clearing an
 * override removes the key rather than storing an empty string — an
 * absent key is what "inherits the wider screen" means, and the renderer
 * reads absent and empty differently.
 */

export type Breakpoint = 'base' | 'tablet' | 'mobile'

/** Narrower breakpoints inherit from the one above when they say nothing. */
const INHERITS: Record<Breakpoint, Breakpoint | null> = {
    base: null,
    tablet: 'base',
    mobile: 'tablet',
}

interface ResponsiveValue {
    $responsive: Record<string, unknown>
}

function isResponsive(value: unknown): value is ResponsiveValue {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as ResponsiveValue).$responsive === 'object' &&
        (value as ResponsiveValue).$responsive !== null
    )
}

/** The value declared AT this breakpoint, ignoring what it would inherit. */
export function declaredAt(value: unknown, breakpoint: Breakpoint): string {
    if (!isResponsive(value)) {
        return breakpoint === 'base' && (typeof value === 'string' || typeof value === 'number')
            ? String(value)
            : ''
    }

    const at = value.$responsive[breakpoint]

    return typeof at === 'string' || typeof at === 'number' ? String(at) : ''
}

/**
 * What this breakpoint actually renders as, following the inheritance
 * chain — which is what a control should SHOW when nothing is declared
 * here, so the editor sees the value the visitor will see.
 */
export function effectiveAt(value: unknown, breakpoint: Breakpoint): string {
    let at: Breakpoint | null = breakpoint

    while (at !== null) {
        const declared = declaredAt(value, at)
        if (declared !== '') {
            return declared
        }
        at = INHERITS[at]
    }

    return ''
}

/** Whether this breakpoint shows an inherited value rather than its own. */
export function inheritsAt(value: unknown, breakpoint: Breakpoint): boolean {
    return breakpoint !== 'base' && declaredAt(value, breakpoint) === ''
}

/**
 * The value to store after editing one breakpoint.
 *
 * Returns `undefined` when the key should be REMOVED entirely — clearing
 * the base of a value nothing else overrides leaves nothing worth
 * storing, and a document is easier to read without empty sentinels in it.
 */
export function withBreakpoint(
    current: unknown,
    breakpoint: Breakpoint,
    value: string,
): unknown | undefined {
    const trimmed = value.trim()

    // A scalar stays a scalar for as long as it can: only an override at a
    // narrower width needs the sentinel.
    if (breakpoint === 'base' && !isResponsive(current)) {
        return trimmed === '' ? undefined : trimmed
    }

    const next: Record<string, unknown> = isResponsive(current)
        ? { ...current.$responsive }
        : typeof current === 'string' || typeof current === 'number'
          ? { base: String(current) }
          : {}

    if (trimmed === '') {
        delete next[breakpoint]
    } else {
        next[breakpoint] = trimmed
    }

    const keys = Object.keys(next)
    if (keys.length === 0) {
        return undefined
    }

    // Nothing left but a base? Store it as the scalar it is.
    if (keys.length === 1 && keys[0] === 'base') {
        return next.base
    }

    return { $responsive: next }
}

/**
 * The operations that write one breakpoint of one style key.
 *
 * Same JSON Patch care as every other settings write: `add` needs its
 * parent to exist, and removing is how "not set" is expressed.
 */
export function responsiveStyleOperations(
    currentStyle: unknown,
    pointer: string,
    key: string,
    breakpoint: Breakpoint,
    value: string,
): PatchOperation[] {
    const style =
        typeof currentStyle === 'object' && currentStyle !== null
            ? (currentStyle as Record<string, unknown>)
            : null
    const next = withBreakpoint(style?.[key], breakpoint, value)

    if (style === null) {
        return next === undefined
            ? []
            : [{ op: 'add', path: `${pointer}/settings/style`, value: { [key]: next } }]
    }

    if (next === undefined) {
        return key in style ? [{ op: 'remove', path: `${pointer}/settings/style/${key}` }] : []
    }

    return [{ op: 'add', path: `${pointer}/settings/style/${key}`, value: next }]
}
