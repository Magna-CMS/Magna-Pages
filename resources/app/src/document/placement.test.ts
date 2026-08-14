import { describe, expect, it } from 'vitest'

import { ASSET_PLACEMENT, canDrop, describeSource, needsImportFlow, placementOf } from './placement'

describe('placement', () => {
    it('sends blocks to columns and sections to the canvas', () => {
        expect(canDrop({ kind: 'new', handle: 'heading' }, 'column')).toBe(true)
        expect(canDrop({ kind: 'new', handle: 'heading' }, 'canvas')).toBe(false)

        expect(canDrop({ kind: 'move', nodeId: 'blk-1' }, 'column')).toBe(true)

        expect(canDrop({ kind: 'library', slug: 'hero', assetKind: 'pattern' }, 'canvas')).toBe(true)
        expect(canDrop({ kind: 'library', slug: 'hero', assetKind: 'pattern' }, 'column')).toBe(false)

        expect(canDrop({ kind: 'library', slug: 'cta', assetKind: 'block' }, 'column')).toBe(true)
        expect(canDrop({ kind: 'pattern', id: 'p1', assetKind: 'block' }, 'column')).toBe(true)
    })

    it('lets anything a column accepts land in a container too', () => {
        // A block is a block wherever it sits; the difference between a
        // column and a container is where the drop lands, not whether it
        // is allowed.
        expect(canDrop({ kind: 'new', handle: 'heading' }, 'container')).toBe(true)
        expect(canDrop({ kind: 'move', nodeId: 'blk-1' }, 'container')).toBe(true)
        expect(canDrop({ kind: 'library', slug: 'cta', assetKind: 'block' }, 'container')).toBe(true)

        // A section subtree still belongs between sections, never inside one.
        expect(canDrop({ kind: 'library', slug: 'hero', assetKind: 'pattern' }, 'container')).toBe(
            false,
        )
    })

    it('routes whole pages through the import flow instead of a drop', () => {
        const page = { kind: 'library', slug: 'landing', assetKind: 'page' } as const

        expect(needsImportFlow(page)).toBe(true)
        // A page is never a valid drop target anywhere — dropping one would
        // silently discard the document being edited.
        expect(canDrop(page, 'canvas')).toBe(false)
        expect(canDrop(page, 'column')).toBe(false)
        expect(canDrop(page, 'container')).toBe(false)
    })

    it('refuses unknown asset kinds rather than guessing a placement', () => {
        const future = { kind: 'library', slug: 'x', assetKind: 'hologram' } as const

        expect(placementOf(future)).toBeNull()
        expect(canDrop(future, 'column')).toBe(false)
        expect(canDrop(future, 'container')).toBe(false)
        expect(canDrop(future, 'canvas')).toBe(false)
    })

    it('keeps every known kind in one table', () => {
        // The extensibility contract: adding a kind is adding a row here.
        expect(Object.keys(ASSET_PLACEMENT).sort()).toEqual([
            'block',
            'page',
            'part',
            'pattern',
            'section',
        ])
    })

    it('describes each source for the drag ghost', () => {
        expect(describeSource({ kind: 'new', handle: 'button' })).toBe('button')
        expect(describeSource({ kind: 'move', nodeId: 'n' })).toBe('element')
    })
})
