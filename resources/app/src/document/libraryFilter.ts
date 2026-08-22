import type { LibraryAssetSummary } from '../stores/document'

/**
 * The browser's filtering, out of the component so it can be tested
 * without mounting anything: which assets survive a search term and a
 * kind chip, and which kinds are worth offering as chips at all.
 */

export interface LibraryFilter {
    search: string
    /** '' means every kind. */
    kind: string
}

export function filterAssets(
    assets: LibraryAssetSummary[],
    filter: LibraryFilter,
): LibraryAssetSummary[] {
    const needle = filter.search.trim().toLowerCase()

    return assets.filter((asset) => {
        if (filter.kind !== '' && asset.kind !== filter.kind) {
            return false
        }
        if (needle === '') {
            return true
        }

        return [asset.name, asset.description ?? '', asset.kind].some((hay) =>
            hay.toLowerCase().includes(needle),
        )
    })
}

/**
 * The kind chips to offer: only kinds the catalog actually contains, in a
 * stable order. A chip that always filters to nothing teaches the editor
 * that chips are decorative.
 */
export function kindChips(assets: LibraryAssetSummary[]): string[] {
    const order = ['pattern', 'part', 'page', 'block']
    const present = new Set(assets.map((asset) => asset.kind))

    return order.filter((kind) => present.has(kind))
}
