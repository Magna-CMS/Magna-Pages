import { describe, expect, it } from 'vitest'

import { insertTag, tagToken } from './tags'

/**
 * Inserting an inline `{tag:…}` token.
 *
 * The behaviour worth pinning is the caret: the original inserter appended,
 * so inserting a tag while editing the middle of a sentence put it after the
 * full stop. Everything else here is the defensive edge — an offset arriving
 * from the DOM can be stale, and slicing a string with a bad index does not
 * throw, it silently puts the token somewhere nobody chose.
 */
describe('insertTag', () => {
    it('writes the token where the caret is', () => {
        const result = insertTag('Hello  world', 'site.year', 6)

        expect(result.value).toBe('Hello {tag:site.year} world')
    })

    it('leaves the caret just past the token, ready to keep typing', () => {
        const result = insertTag('Hello ', 'site.year', 6)

        expect(result.caret).toBe(6 + tagToken('site.year').length)
        expect(result.value.slice(result.caret)).toBe('')
    })

    it('appends when the caret is at the end, as it always did', () => {
        expect(insertTag('Total: ', 'shop.total', 7).value).toBe('Total: {tag:shop.total}')
    })

    it('inserts at the start', () => {
        expect(insertTag('is the year', 'site.year', 0).value).toBe('{tag:site.year}is the year')
    })

    // Select a placeholder, insert the tag over it — what a paste would do.
    it('replaces a selection', () => {
        const result = insertTag('Year: XXXX here', 'site.year', 6, 10)

        expect(result.value).toBe('Year: {tag:site.year} here')
        expect(result.caret).toBe(6 + tagToken('site.year').length)
    })

    it('does not care which end of the selection came first', () => {
        expect(insertTag('Year: XXXX', 'site.year', 10, 6).value).toBe('Year: {tag:site.year}')
    })

    /*
     * A stale offset is the realistic failure: the field re-rendered under the
     * caret between the click and the insert. Landing at the end is the safe
     * reading — it is where an append would have gone, and it never cuts a
     * value in half.
     */
    it('falls back to the end when the offset is past the value', () => {
        expect(insertTag('short', 'site.year', 999).value).toBe('short{tag:site.year}')
    })

    it('treats a negative offset as the start, not as counting backwards', () => {
        expect(insertTag('abc', 'site.year', -4).value).toBe('{tag:site.year}abc')
    })

    it('survives an offset that is not a number at all', () => {
        expect(insertTag('abc', 'site.year', Number.NaN).value).toBe('abc{tag:site.year}')
    })

    it('inserts into an empty value', () => {
        expect(insertTag('', 'site.year', 0).value).toBe('{tag:site.year}')
    })
})
