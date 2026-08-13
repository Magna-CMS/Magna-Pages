import { describe, expect, it } from 'vitest'

import {
    declaredAt,
    effectiveAt,
    inheritsAt,
    responsiveStyleOperations,
    withBreakpoint,
} from './responsive'

/**
 * These tests guard the property the renderer depends on: a value that
 * nobody made per-device stays a plain scalar. A document that silently
 * grew sentinels would render the same but read differently, and every
 * consumer of the format — cloud library, delivery API, the other editor —
 * would have to learn about them for no reason.
 */
const RESPONSIVE = { $responsive: { base: '96px', mobile: '24px' } }

describe('reading', () => {
    it('reads a scalar as the base and nothing else', () => {
        expect(declaredAt('20px', 'base')).toBe('20px')
        expect(declaredAt('20px', 'mobile')).toBe('')
    })

    it('reads each declared breakpoint', () => {
        expect(declaredAt(RESPONSIVE, 'base')).toBe('96px')
        expect(declaredAt(RESPONSIVE, 'mobile')).toBe('24px')
        expect(declaredAt(RESPONSIVE, 'tablet')).toBe('')
    })

    it('follows the inheritance chain for what actually renders', () => {
        // Tablet says nothing, so tablet renders the base; mobile says
        // something, so it does not.
        expect(effectiveAt(RESPONSIVE, 'tablet')).toBe('96px')
        expect(effectiveAt(RESPONSIVE, 'mobile')).toBe('24px')
        expect(inheritsAt(RESPONSIVE, 'tablet')).toBe(true)
        expect(inheritsAt(RESPONSIVE, 'mobile')).toBe(false)
    })

    it('inherits through an empty middle', () => {
        const value = { $responsive: { base: '10px' } }

        expect(effectiveAt(value, 'mobile')).toBe('10px')
    })
})

describe('writing', () => {
    it('keeps a scalar a scalar while only the base is edited', () => {
        expect(withBreakpoint('20px', 'base', '30px')).toBe('30px')
        expect(withBreakpoint(undefined, 'base', '30px')).toBe('30px')
    })

    it('promotes to a sentinel when a narrower width is overridden', () => {
        expect(withBreakpoint('20px', 'mobile', '8px')).toEqual({
            $responsive: { base: '20px', mobile: '8px' },
        })
    })

    it('demotes back to a scalar when the last override is cleared', () => {
        // A document should not carry a sentinel that says nothing.
        expect(withBreakpoint(RESPONSIVE, 'mobile', '')).toBe('96px')
    })

    it('removes the key entirely when nothing is left', () => {
        expect(withBreakpoint('20px', 'base', '')).toBeUndefined()
        expect(withBreakpoint({ $responsive: { mobile: '8px' } }, 'mobile', '  ')).toBeUndefined()
    })

    it('edits one breakpoint without touching the others', () => {
        expect(withBreakpoint(RESPONSIVE, 'tablet', '48px')).toEqual({
            $responsive: { base: '96px', mobile: '24px', tablet: '48px' },
        })
    })
})

describe('operations', () => {
    it('creates the style object the first time', () => {
        expect(responsiveStyleOperations(undefined, '/0', 'paddingTop', 'mobile', '8px')).toEqual([
            { op: 'add', path: '/0/settings/style', value: { paddingTop: { $responsive: { mobile: '8px' } } } },
        ])
    })

    it('writes one key once the object exists', () => {
        expect(
            responsiveStyleOperations({ paddingTop: '20px' }, '/0', 'paddingTop', 'mobile', '8px'),
        ).toEqual([
            {
                op: 'add',
                path: '/0/settings/style/paddingTop',
                value: { $responsive: { base: '20px', mobile: '8px' } },
            },
        ])
    })

    it('removes the key when the value empties out', () => {
        expect(responsiveStyleOperations({ paddingTop: '20px' }, '/0', 'paddingTop', 'base', '')).toEqual([
            { op: 'remove', path: '/0/settings/style/paddingTop' },
        ])
    })

    it('does nothing when clearing something never set', () => {
        expect(responsiveStyleOperations({}, '/0', 'paddingTop', 'mobile', '')).toEqual([])
        expect(responsiveStyleOperations(undefined, '/0', 'paddingTop', 'base', '')).toEqual([])
    })
})
