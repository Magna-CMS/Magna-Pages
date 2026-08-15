import { describe, expect, it } from 'vitest'

import { completeTag, matchTags, openTagQuery } from './tagAutocomplete'

const sources = {
    'site.year': 'Current year',
    'shop.basket_total': 'Basket total',
    'fixture.unread': 'Unread messages',
}

describe('openTagQuery', () => {
    it('finds the token being typed at the caret', () => {
        const value = 'Since {tag:si'

        expect(openTagQuery(value, value.length)).toEqual({ query: 'si', from: 6, to: 13 })
    })

    it('offers everything the moment the token opens', () => {
        const value = 'Since {tag:'

        expect(openTagQuery(value, value.length)?.query).toBe('')
    })

    it('swallows a closing brace the caret is sitting inside', () => {
        // `{tag:si|}` — completing this must replace the brace too, or the
        // page ships `{tag:site.year}}` with a stray brace in the prose.
        const value = 'Since {tag:si}'
        const open = openTagQuery(value, 13)

        expect(open).toEqual({ query: 'si', from: 6, to: 14 })
        expect(completeTag(value, 'site.year', open!).value).toBe('Since {tag:site.year}')
    })

    it('is not fooled by text that merely contains braces', () => {
        expect(openTagQuery('a { b', 5)).toBeNull()
        expect(openTagQuery('{tag:site.year} and then', 24)).toBeNull()
        // A space ends it: this is prose, not a handle.
        expect(openTagQuery('{tag: site', 10)).toBeNull()
    })

    it('reads the caret where it is, not at the end', () => {
        const value = 'Since {tag:site} — {tag:sh'

        // Caret inside the FIRST token.
        expect(openTagQuery(value, 15)?.query).toBe('site')
        // …and inside the second.
        expect(openTagQuery(value, value.length)?.query).toBe('sh')
    })

    it('clamps a caret that has gone stale', () => {
        const value = 'Since {tag:si'

        expect(openTagQuery(value, 9999)?.query).toBe('si')
        expect(openTagQuery(value, -3)).toBeNull()
    })
})

describe('matchTags', () => {
    it('puts a handle that starts with the query first', () => {
        expect(matchTags(sources, 'si').map((match) => match.handle)).toEqual(['site.year'])
    })

    it('falls back to what the tag is called', () => {
        expect(matchTags(sources, 'unread').map((match) => match.handle)).toEqual(['fixture.unread'])
        expect(matchTags(sources, 'basket').map((match) => match.handle)).toEqual([
            'shop.basket_total',
        ])
    })

    it('ranks a handle match above a label match', () => {
        const ranked = matchTags(
            { 'a.total': 'Something else', 'b.other': 'Total of things' },
            'total',
        )

        expect(ranked.map((match) => match.handle)).toEqual(['a.total', 'b.other'])
    })

    it('offers everything alphabetically for an empty query', () => {
        expect(matchTags(sources, '').map((match) => match.handle)).toEqual([
            'fixture.unread',
            'shop.basket_total',
            'site.year',
        ])
    })

    it('answers nothing rather than everything when nothing matches', () => {
        expect(matchTags(sources, 'zzz')).toEqual([])
    })

    it('caps the list at something a person can read', () => {
        const many: Record<string, string> = {}
        for (let index = 0; index < 30; index++) {
            many[`tag.number-${index}`] = `Tag ${index}`
        }

        expect(matchTags(many, 'tag')).toHaveLength(8)
    })
})

describe('completeTag', () => {
    it('replaces the half-typed token rather than inserting beside it', () => {
        const value = 'Since {tag:si and more'
        const open = openTagQuery(value, 13)!

        expect(completeTag(value, 'site.year', open)).toEqual({
            value: 'Since {tag:site.year} and more',
            caret: 21,
        })
    })

    it('leaves the caret past the token, ready to keep typing', () => {
        const value = '{tag:'
        const { value: next, caret } = completeTag(value, 'site.year', openTagQuery(value, 5)!)

        expect(next.slice(0, caret)).toBe('{tag:site.year}')
    })
})
