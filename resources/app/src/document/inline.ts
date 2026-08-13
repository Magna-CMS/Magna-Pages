import type { BlockDefinition, BlockFieldDefinition } from './types'

/**
 * Which fields may be edited on the canvas, and how.
 *
 * A capability table keyed by FIELD TYPE, not a hardcoded "first text
 * field" rule: a block that ships a new field type opts into inline
 * editing by declaring that type, with no change here — the same promise
 * the schema-driven inspector already makes.
 *
 * `plain` means the element's text is the value: safe to read back as
 * text, because that is all it ever was. `rich` means the value is
 * markup, which is a different editor and a different commit path — a
 * plain-text read of a rich field would silently destroy its markup, and
 * that is the mistake this table exists to make impossible.
 */

export type InlineMode = 'plain' | 'rich' | 'none'

const MODES: Record<string, InlineMode> = {
    text: 'plain',
    textarea: 'plain',
    richtext: 'rich',
    // A link's LABEL is text worth editing on the canvas; its URL is not
    // something to type into a rendered anchor.
    link: 'rich',
}

export function inlineModeFor(field: BlockFieldDefinition): InlineMode {
    return MODES[field.type] ?? 'none'
}

/**
 * The fields a block exposes for inline editing, in the order the canvas
 * should offer them.
 *
 * A block may declare `inlineFields` to expose more than one, or to
 * choose a different one from the default. Absent, the first eligible
 * field wins — which is exactly the behaviour that shipped before this
 * table existed, so no block.json needs changing.
 */
export function inlineFieldsOf(definition: BlockDefinition): BlockFieldDefinition[] {
    const eligible = definition.fields.filter((field) => inlineModeFor(field) !== 'none')

    const declared = definition.inlineFields
    if (Array.isArray(declared) && declared.length > 0) {
        // Declared order wins, and a handle that names a field this table
        // cannot edit is ignored rather than trusted.
        return declared
            .map((handle) => eligible.find((field) => field.handle === handle))
            .filter((field): field is BlockFieldDefinition => field !== undefined)
    }

    // Required first, matching what primaryTextField chose: a block's
    // required text is the one an editor means when they click it.
    const required = eligible.find((field) => field.required)

    return required ? [required] : eligible.slice(0, 1)
}

/**
 * The field a click on this block should edit, with the mode to edit it
 * in — or null when nothing on this block is editable in place.
 */
export function inlineTarget(
    definition: BlockDefinition,
    data: Record<string, unknown> | undefined,
): { handle: string; mode: InlineMode } | null {
    for (const field of inlineFieldsOf(definition)) {
        // A bound value resolves at render. Typing over the resolved
        // output would replace the binding with a literal, silently.
        const value = data?.[field.handle]
        if (typeof value === 'object' && value !== null && '$bind' in value) {
            continue
        }

        return { handle: field.handle, mode: inlineModeFor(field) }
    }

    return null
}
