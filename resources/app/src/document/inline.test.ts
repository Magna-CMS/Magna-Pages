import { describe, expect, it } from 'vitest'

import { inlineBlockRefusal, inlineFieldsOf, inlineModeFor, inlineTarget } from './inline'
import type { BlockDefinition, BlockFieldDefinition } from './types'

/**
 * The rule these tests protect: a rich field must never be treated as a
 * plain one. Reading a richtext value back as text is how stored markup
 * gets destroyed, and it would look like an editor's own typo.
 */
function field(overrides: Partial<BlockFieldDefinition> = {}): BlockFieldDefinition {
    return {
        handle: 'text',
        type: 'text',
        label: 'Text',
        required: false,
        default: null,
        options: {},
        multiple: false,
        fields: [],
        ...overrides,
    }
}

function block(fields: BlockFieldDefinition[], inlineFields?: string[]): BlockDefinition {
    return {
        handle: 'demo',
        label: 'Demo',
        icon: '',
        category: 'content',
        requiresPermission: null,
        fields,
        ...(inlineFields ? { inlineFields } : {}),
    }
}

describe('inlineModeFor', () => {
    it('reads text as plain and markup as rich', () => {
        expect(inlineModeFor(field({ type: 'text' }))).toBe('plain')
        expect(inlineModeFor(field({ type: 'textarea' }))).toBe('plain')
        expect(inlineModeFor(field({ type: 'richtext' }))).toBe('rich')
    })

    it('refuses a field type it has never heard of', () => {
        // The default is "not editable in place" — a new field type opts
        // IN by being named, never by being unrecognised.
        expect(inlineModeFor(field({ type: 'image' }))).toBe('none')
        expect(inlineModeFor(field({ type: 'repeater' }))).toBe('none')
    })
})

describe('inlineFieldsOf', () => {
    it('falls back to the required text field, as it always did', () => {
        const definition = block([
            field({ handle: 'eyebrow' }),
            field({ handle: 'headline', required: true }),
        ])

        expect(inlineFieldsOf(definition).map((f) => f.handle)).toEqual(['headline'])
    })

    it('takes the first eligible field when none is required', () => {
        const definition = block([field({ handle: 'image', type: 'image' }), field({ handle: 'caption' })])

        expect(inlineFieldsOf(definition).map((f) => f.handle)).toEqual(['caption'])
    })

    it('honours a block that declares its own, in its own order', () => {
        const definition = block(
            [field({ handle: 'headline', required: true }), field({ handle: 'body', type: 'richtext' })],
            ['body', 'headline'],
        )

        expect(inlineFieldsOf(definition).map((f) => f.handle)).toEqual(['body', 'headline'])
    })

    it('ignores a declared handle this table cannot edit', () => {
        const definition = block(
            [field({ handle: 'photo', type: 'image' }), field({ handle: 'caption' })],
            ['photo', 'caption'],
        )

        expect(inlineFieldsOf(definition).map((f) => f.handle)).toEqual(['caption'])
    })

    it('offers nothing for a block with no editable field', () => {
        expect(inlineFieldsOf(block([field({ type: 'image' })]))).toEqual([])
    })
})

describe('inlineTarget', () => {
    it('names the field and how to edit it', () => {
        const definition = block([field({ handle: 'body', type: 'richtext' })])

        expect(inlineTarget(definition, { body: '<p>Hi</p>' })).toEqual({
            handle: 'body',
            mode: 'rich',
        })
    })

    it('skips a bound field and moves to the next one', () => {
        // A binding resolves at render; typing over the resolved output
        // would replace it with a literal without saying so.
        const definition = block(
            [field({ handle: 'headline' }), field({ handle: 'body', type: 'richtext' })],
            ['headline', 'body'],
        )

        expect(inlineTarget(definition, { headline: { $bind: 'page.title' } })).toEqual({
            handle: 'body',
            mode: 'rich',
        })
    })

    it('gives up when every candidate is bound', () => {
        const definition = block([field({ handle: 'headline' })])

        expect(inlineTarget(definition, { headline: { $bind: 'page.title' } })).toBeNull()
    })
})

describe('inlineBlockRefusal', () => {
    it('says nothing when there is something to type over', () => {
        expect(inlineBlockRefusal(block([field({ required: true })]), {})).toBeNull()
    })

    it('names the block that has no text at all', () => {
        // A silent refusal is what teaches an editor that the canvas does
        // not edit, so a block with nothing to type over has to say so.
        const refusal = inlineBlockRefusal(block([field({ type: 'image' })]), {})

        expect(refusal).toContain('Demo')
        expect(refusal).toContain('panel')
    })

    it('explains a bound value instead of letting a click do nothing', () => {
        const refusal = inlineBlockRefusal(block([field({ handle: 'text', required: true })]), {
            text: { $bind: 'entry.title' },
        })

        expect(refusal).toContain('dynamic data')
        expect(refusal).toContain('Unbind')
    })
})
