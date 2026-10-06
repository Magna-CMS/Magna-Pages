import { describe, expect, it } from 'vitest'

import { jsonFieldEdit, jsonFieldText } from './jsonField'

/**
 * The rule these tests protect: a json field's stored list must survive being
 * looked at, and must never be replaced by text that does not parse.
 *
 * Before this existed, a features block whose `items` was an array rendered an
 * empty one-line box, and typing into it wrote that string over the array —
 * content destroyed by an edit nobody meant to make.
 */
describe('what the editor shows', () => {
    it('renders a stored list as readable JSON rather than nothing', () => {
        const items = [{ title: 'Offline first', body: 'Works without a connection.' }]

        expect(jsonFieldText(items)).toBe(JSON.stringify(items, null, 2))
    })

    it('shows an object the same way', () => {
        expect(jsonFieldText({ a: 1 })).toBe('{\n  "a": 1\n}')
    })

    it('leaves a value that was already text exactly as it was stored', () => {
        // Some documents store the list as a JSON string; the render side
        // accepts both. Re-encoding it here would rewrite a document nobody
        // edited.
        expect(jsonFieldText('[{"title":"Kept"}]')).toBe('[{"title":"Kept"}]')
    })

    it('shows an empty box for a field that holds nothing', () => {
        expect(jsonFieldText(null)).toBe('')
        expect(jsonFieldText(undefined)).toBe('')
    })
})

describe('what an edit means', () => {
    it('parses a list into the value that gets stored', () => {
        const result = jsonFieldEdit('[{"title":"Offline first"}]')

        expect(result.ok).toBe(true)
        expect(result.ok && result.value).toEqual([{ title: 'Offline first' }])
    })

    it('treats an emptied box as clearing the field', () => {
        const result = jsonFieldEdit('   ')

        expect(result.ok).toBe(true)
        expect(result.ok && result.value).toBeNull()
    })

    it('refuses text that does not parse, and says so', () => {
        const result = jsonFieldEdit('[{"title": "half typed"')

        expect(result.ok).toBe(false)
        expect(result.ok === false && result.message).not.toBe('')
    })

    it('never returns a value for an unparseable edit', () => {
        // The whole point: there is no branch where a broken edit carries a
        // value forward, because that value would overwrite the list.
        const result = jsonFieldEdit('not json at all')

        expect('value' in result).toBe(false)
    })
})
