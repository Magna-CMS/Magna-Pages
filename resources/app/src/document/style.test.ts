import { describe, expect, it } from 'vitest'

import { styleOperations } from './edits'

/**
 * Style writes are ordinary patch operations, and JSON Patch is strict
 * about parents existing — the first style on a node is a different batch
 * from the tenth. These tests exist because getting that wrong produces a
 * refusal the editor sees as "the control does nothing".
 */

const POINTER = '/0'

describe('styleOperations', () => {
    it('creates the style object the first time a node is styled', () => {
        expect(styleOperations(undefined, POINTER, 'paddingTop', '24px')).toEqual([
            { op: 'add', path: '/0/settings/style', value: { paddingTop: '24px' } },
        ])
    })

    it('sets one key once the object exists', () => {
        expect(styleOperations({ background: 'red' }, POINTER, 'paddingTop', '24px')).toEqual([
            { op: 'add', path: '/0/settings/style/paddingTop', value: '24px' },
        ])
    })

    it('removes the key when the value is cleared', () => {
        // Absent is what "not styled" means everywhere else in the
        // document, and the renderer reads absent and empty differently.
        expect(styleOperations({ paddingTop: '24px' }, POINTER, 'paddingTop', '')).toEqual([
            { op: 'remove', path: '/0/settings/style/paddingTop' },
        ])
    })

    it('does nothing when clearing something that was never set', () => {
        expect(styleOperations({ background: 'red' }, POINTER, 'paddingTop', '  ')).toEqual([])
        expect(styleOperations(undefined, POINTER, 'paddingTop', '')).toEqual([])
    })

    it('trims the value it stores', () => {
        expect(styleOperations({}, POINTER, 'paddingTop', '  24px  ')).toEqual([
            { op: 'add', path: '/0/settings/style/paddingTop', value: '24px' },
        ])
    })

    it('addresses the node it was given, not the document root', () => {
        const deep = '/0/columns/1'

        expect(styleOperations({}, deep, 'alignItems', 'center')[0].path).toBe(
            '/0/columns/1/settings/style/alignItems',
        )
    })
})
