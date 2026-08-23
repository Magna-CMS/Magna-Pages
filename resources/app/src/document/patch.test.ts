import { describe, expect, it } from 'vitest'

import { applyPatch, PatchError, toPointer } from './patch'

/**
 * The client applier has to agree with PatchApplier.php operation for
 * operation, or optimistic edits will disagree with what the server stored
 * and the canvas will "snap back" after every save. These tests pin the two
 * semantics that differ between a correct implementation and an obvious one,
 * plus the inverses undo replays.
 */
describe('applyPatch', () => {
    it('inserts before an index in a list rather than overwriting', () => {
        const { document } = applyPatch({ items: ['a', 'c'] }, [
            { op: 'add', path: '/items/1', value: 'b' },
        ])

        expect(document.items).toEqual(['a', 'b', 'c'])
    })

    it('appends with the dash pointer', () => {
        const { document } = applyPatch({ items: ['a'] }, [
            { op: 'add', path: '/items/-', value: 'b' },
        ])

        expect(document.items).toEqual(['a', 'b'])
    })

    it('closes the gap when removing from a list', () => {
        const { document } = applyPatch({ items: ['a', 'b', 'c'] }, [
            { op: 'remove', path: '/items/0' },
        ])

        expect(document.items).toEqual(['b', 'c'])
    })

    it('never mutates the input document', () => {
        const original = { items: ['a'] }
        applyPatch(original, [{ op: 'add', path: '/items/-', value: 'b' }])

        expect(original.items).toEqual(['a'])
    })

    it('rejects a pointer into a node that does not exist', () => {
        expect(() =>
            applyPatch({ items: ['a'] }, [{ op: 'replace', path: '/missing/deep', value: 1 }]),
        ).toThrow(PatchError)
    })

    it('produces an inverse that restores the previous document', () => {
        const before = {
            sections: [{ id: 's1', blocks: [{ id: 'b1', text: 'one' }] }],
        }

        const { document: after, inverse } = applyPatch(before, [
            { op: 'replace', path: '/sections/0/blocks/0/text', value: 'two' },
            { op: 'add', path: '/sections/0/blocks/-', value: { id: 'b2', text: 'new' } },
        ])

        expect(after.sections[0].blocks).toHaveLength(2)

        const { document: undone } = applyPatch(after, inverse)
        expect(undone).toEqual(before)
    })

    it('inverts a removal by restoring the value at its index', () => {
        const before = { items: ['a', 'b', 'c'] }
        const { document: after, inverse } = applyPatch(before, [
            { op: 'remove', path: '/items/1' },
        ])

        expect(after.items).toEqual(['a', 'c'])
        expect(applyPatch(after, inverse).document).toEqual(before)
    })

    it('inverts a move by swapping its endpoints', () => {
        const before = { items: ['a', 'b', 'c'] }
        const { document: after, inverse } = applyPatch(before, [
            { op: 'move', path: '/items/0', from: '/items/2' },
        ])

        expect(after.items).toEqual(['c', 'a', 'b'])
        expect(applyPatch(after, inverse).document).toEqual(before)
    })
})

describe('toPointer', () => {
    it('escapes segments that contain pointer syntax', () => {
        expect(toPointer(['data', 'a/b', 'c~d'])).toBe('/data/a~1b/c~0d')
    })

    it('builds indexed paths', () => {
        expect(toPointer([0, 'columns', 1, 'blocks', 2])).toBe('/0/columns/1/blocks/2')
    })
})

describe('empty maps that arrived as empty arrays', () => {
    /*
     * PHP cannot tell `{}` from `[]`: an empty associative array encodes
     * as a list. A section whose settings are empty therefore comes back
     * from the server as `settings: []`, and writing its FIRST style read
     * the key as an array index — which is why the very first colour set
     * on a freshly added section failed with "index out of range".
     */
    it('writes a key into a settings map that came back as a list', () => {
        const document = [{ id: 's1', type: 'section', settings: [], columns: [] }]

        const applied = applyPatch(document as never, [
            { op: 'add', path: '/0/settings/style', value: { background: '#123456' } },
        ])

        expect((applied.document as never[])[0]).toMatchObject({
            settings: { style: { background: '#123456' } },
        })
    })

    it('still treats a populated list as a list', () => {
        // The ambiguity exists only when the array is empty. A list with
        // anything in it is unambiguously a list, and must stay one.
        const document = [{ id: 's1', type: 'section', settings: {}, columns: [{ id: 'c1' }] }]

        const applied = applyPatch(document as never, [
            { op: 'add', path: '/0/columns/-', value: { id: 'c2' } },
        ])

        expect(((applied.document as never[])[0] as { columns: unknown[] }).columns).toHaveLength(2)
    })

    it('still refuses a real out-of-range index', () => {
        const document = [{ id: 's1', columns: [{ id: 'c1' }] }]

        expect(() =>
            applyPatch(document as never, [{ op: 'add', path: '/0/columns/9', value: { id: 'c2' } }]),
        ).toThrow()
    })
})
