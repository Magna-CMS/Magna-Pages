import { describe, expect, it } from 'vitest'

import { filterAssets, kindChips } from './libraryFilter'
import type { LibraryAssetSummary } from '../stores/document'

function asset(overrides: Partial<LibraryAssetSummary>): LibraryAssetSummary {
    return {
        slug: 'x',
        name: 'X',
        kind: 'pattern',
        description: null,
        missingBlocks: [],
        downloads: 0,
        ...overrides,
    }
}

const CATALOG = [
    asset({ slug: 'hero', name: 'Hero banner', kind: 'pattern', description: 'Centred hero' }),
    asset({ slug: 'cta', name: 'Call-to-action', kind: 'block' }),
    asset({ slug: 'landing', name: 'Launch landing', kind: 'page' }),
]

describe('filterAssets', () => {
    it('matches name, description and kind, case-insensitively', () => {
        expect(filterAssets(CATALOG, { search: 'HERO', kind: '' })).toHaveLength(1)
        expect(filterAssets(CATALOG, { search: 'centred', kind: '' })).toHaveLength(1)
        expect(filterAssets(CATALOG, { search: 'page', kind: '' })).toHaveLength(1)
    })

    it('intersects the kind chip with the search', () => {
        expect(filterAssets(CATALOG, { search: '', kind: 'block' })).toHaveLength(1)
        expect(filterAssets(CATALOG, { search: 'hero', kind: 'block' })).toHaveLength(0)
    })

    it('passes everything through an empty filter', () => {
        expect(filterAssets(CATALOG, { search: '  ', kind: '' })).toHaveLength(3)
    })
})

describe('kindChips', () => {
    it('offers only kinds the catalog contains, in stable order', () => {
        // No 'part' in the catalog, so no part chip: a chip that always
        // filters to nothing is decoration.
        expect(kindChips(CATALOG)).toEqual(['pattern', 'page', 'block'])
        expect(kindChips([])).toEqual([])
    })
})
