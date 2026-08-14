import { describe, expect, it } from 'vitest'

import {
    appendSection,
    blockFrom,
    duplicateNode,
    emptySection,
    exportAsLibraryAsset,
    insertBlock,
    moveNode,
    removeNode,
    withFreshIds,
} from './edits'
import { applyPatch } from './patch'
import type {
    BlockDefinition,
    BlockDocument,
    BlockNode,
    PatchOperation,
    SectionNode,
} from './types'

const heading: BlockDefinition = {
    handle: 'heading',
    label: 'Heading',
    icon: 'h',
    category: 'content',
    requiresPermission: null,
    fields: [
        {
            handle: 'text',
            type: 'text',
            label: 'Text',
            required: true,
            default: 'New heading',
            options: {},
            multiple: false,
            fields: [],
        },
    ],
}

// Typed as the list form rather than the union so the assertions can index
// into it; the wrapped form has its own test below.
function documentWith(blockIds: string[]): SectionNode[] {
    return [
        {
            id: 'sec-1',
            type: 'section',
            columns: [
                {
                    id: 'col-1',
                    span: 12,
                    blocks: blockIds.map((id) => ({ id, block: 'heading' })),
                },
                { id: 'col-2', span: 12, blocks: [] },
            ],
        },
    ]
}

describe('blockFrom', () => {
    it('seeds a block with its declared defaults and a fresh id', () => {
        const block = blockFrom(heading)

        expect(block.block).toBe('heading')
        expect(block.data).toEqual({ text: 'New heading' })
        expect(block.id).toHaveLength(26)
    })

    it('placeholders a required text field that has no default, so the block is insertable', () => {
        const definition = {
            ...heading,
            fields: [
                { ...heading.fields[0], handle: 'text', required: true, default: null, label: 'Text' },
                { ...heading.fields[0], handle: 'note', required: false, default: null, label: 'Note' },
            ],
        }

        const block = blockFrom(definition)

        // Required-without-default gets the label; optional stays absent.
        expect(block.data).toEqual({ text: 'Text' })
    })
})

describe('insertBlock', () => {
    it('appends when no index is given', () => {
        const document = documentWith(['a', 'b'])
        const operations = insertBlock(document, 'col-1', blockFrom(heading))

        expect(operations?.[0].path).toBe('/0/columns/0/blocks/-')

        const { document: after } = applyPatch(document, operations ?? [])
        expect(after[0].columns?.[0].blocks).toHaveLength(3)
    })

    it('inserts at a position without displacing its neighbour', () => {
        const document = documentWith(['a', 'c'])
        const operations = insertBlock(document, 'col-1', { id: 'b', block: 'heading' }, 1)

        const { document: after } = applyPatch(document, operations ?? [])
        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['a', 'b', 'c'])
    })

    it('refuses a column that is not in the document', () => {
        expect(insertBlock(documentWith(['a']), 'nope', blockFrom(heading))).toBeNull()
    })
})

describe('moveNode', () => {
    it('accounts for the removal when moving forward in the same column', () => {
        const document = documentWith(['a', 'b', 'c'])

        // Drag "a" to sit where "c" is: after the removal that is index 2.
        const operations = moveNode(document, 'a', 'col-1', 3)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['b', 'c', 'a'])
    })

    it('does not adjust when moving backward in the same column', () => {
        const document = documentWith(['a', 'b', 'c'])

        const operations = moveNode(document, 'c', 'col-1', 0)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['c', 'a', 'b'])
    })

    it('moves a block into another column', () => {
        const document = documentWith(['a', 'b'])

        const operations = moveNode(document, 'a', 'col-2', 0)
        const { document: after } = applyPatch(document, operations ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['b'])
        expect(after[0].columns?.[1].blocks?.map((b) => b.id)).toEqual(['a'])
    })

    it('is a no-op when the block is already where it is dropped', () => {
        expect(moveNode(documentWith(['a', 'b']), 'a', 'col-1', 0)).toEqual([])
    })
})

describe('removeNode', () => {
    it('removes a block and closes the gap', () => {
        const document = documentWith(['a', 'b', 'c'])
        const { document: after } = applyPatch(document, removeNode(document, 'b') ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['a', 'c'])
    })

    it('returns null for an unknown node', () => {
        expect(removeNode(documentWith(['a']), 'nope')).toBeNull()
    })
})

describe('appendSection', () => {
    it('appends to a legacy list document', () => {
        expect(appendSection(documentWith([]), emptySection())[0].path).toBe('/-')
    })

    it('appends inside the sections key of a wrapped document', () => {
        const wrapped: BlockDocument = { schemaVersion: '1.0', sections: [] }

        expect(appendSection(wrapped, emptySection())[0].path).toBe('/sections/-')
    })
})


describe('exportAsLibraryAsset', () => {
    it('exports a section as a pattern with computed required blocks', () => {
        const asset = exportAsLibraryAsset(documentWith(['a', 'b']), 'sec-1', 'My band')

        expect(asset?.kind).toBe('pattern')
        expect(asset?.name).toBe('My band')
        expect(asset?.requiredBlocks).toEqual(['heading'])
        expect((asset?.document as { id: string }).id).toBe('sec-1')
    })

    it('wraps a block selection into an insertable section', () => {
        const asset = exportAsLibraryAsset(documentWith(['a']), 'a', 'One block')

        const doc = asset?.document as { type: string; columns: { blocks: { id: string }[] }[] }
        expect(doc.type).toBe('section')
        expect(doc.columns[0].blocks[0].id).toBe('a')
    })

    it('exports the whole page when nothing is selected, refusing empty pages', () => {
        const asset = exportAsLibraryAsset(documentWith(['a']), null, 'Whole page')

        expect(asset?.kind).toBe('page')
        expect(Array.isArray(asset?.document)).toBe(true)

        expect(exportAsLibraryAsset([], null, 'Empty')).toBeNull()
    })

    it('refuses column selections', () => {
        expect(exportAsLibraryAsset(documentWith(['a']), 'col-1', 'Nope')).toBeNull()
    })
})

/**
 * Nesting. Everything below asserts the same claim from a different angle:
 * a container holds blocks exactly the way a column does, through the same
 * producers — there is no second set of nested edits to keep in step.
 */
const container: BlockDefinition = {
    ...heading,
    handle: 'container',
    label: 'Container',
    container: true,
    fields: [],
}

/** col-1: [a, box[ x, y ]], col-2: []. */
function nestedDocument(): SectionNode[] {
    return [
        {
            id: 'sec-1',
            type: 'section',
            columns: [
                {
                    id: 'col-1',
                    span: 6,
                    blocks: [
                        { id: 'a', block: 'heading' },
                        {
                            id: 'box',
                            block: 'container',
                            children: [
                                { id: 'x', block: 'heading' },
                                { id: 'y', block: 'heading' },
                            ],
                        },
                    ],
                },
                { id: 'col-2', span: 6, blocks: [] },
            ],
        },
    ]
}

function childrenOf(document: SectionNode[], columnIndex = 0): string[] {
    const box = document[0].columns?.[columnIndex].blocks?.find((block) => block.id === 'box')

    return (box?.children ?? []).map((child) => child.id)
}

describe('blockFrom for a container', () => {
    it('gives a container the list it holds, so its first child can be added', () => {
        // JSON Patch cannot add THROUGH a path that does not exist; a
        // container born without `children` would refuse its own first drop.
        expect(blockFrom(container).children).toEqual([])
        expect(blockFrom(heading).children).toBeUndefined()
    })
})

describe('insertBlock into a container', () => {
    it('appends to a container the way it appends to a column', () => {
        const document = nestedDocument()
        const operations = insertBlock(document, 'box', { id: 'z', block: 'heading' })

        expect(operations?.[0].path).toBe('/0/columns/0/blocks/1/children/-')

        const { document: after } = applyPatch(document, operations ?? [])
        expect(childrenOf(after)).toEqual(['x', 'y', 'z'])
    })

    it('inserts at a position inside a container', () => {
        const document = nestedDocument()
        const operations = insertBlock(document, 'box', { id: 'z', block: 'heading' }, 1)

        const { document: after } = applyPatch(document, operations ?? [])
        expect(childrenOf(after)).toEqual(['x', 'z', 'y'])
    })

    it('creates the list when a container has never held anything', () => {
        const document: SectionNode[] = [
            {
                id: 'sec-1',
                type: 'section',
                columns: [{ id: 'col-1', span: 12, blocks: [{ id: 'box', block: 'container' }] }],
            },
        ]

        const operations = insertBlock(document, 'box', { id: 'x', block: 'heading' })
        expect(operations).toEqual([
            {
                op: 'add',
                path: '/0/columns/0/blocks/0/children',
                value: [{ id: 'x', block: 'heading' }],
            },
        ])

        const { document: after } = applyPatch(document, operations ?? [])
        expect(after[0].columns?.[0].blocks?.[0].children?.map((c) => c.id)).toEqual(['x'])
    })
})

describe('moveNode across parents', () => {
    it('moves a root block into a container', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(
            document,
            moveNode(document, 'a', 'box', 0) ?? [],
        )

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['box'])
        expect(childrenOf(after)).toEqual(['a', 'x', 'y'])
    })

    it('moves a nested block back out to its column', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(
            document,
            moveNode(document, 'x', 'col-1', 0) ?? [],
        )

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['x', 'a', 'box'])
        expect(childrenOf(after)).toEqual(['y'])
    })

    it('moves a nested block into another column', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(
            document,
            moveNode(document, 'y', 'col-2', 0) ?? [],
        )

        expect(after[0].columns?.[1].blocks?.map((b) => b.id)).toEqual(['y'])
        expect(childrenOf(after)).toEqual(['x'])
    })

    it('reorders inside a container', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(
            document,
            moveNode(document, 'x', 'box', 2) ?? [],
        )

        expect(childrenOf(after)).toEqual(['y', 'x'])
    })

    it('moves between two containers', () => {
        const document = nestedDocument()
        const withSecond = applyPatch(
            document,
            insertBlock(document, 'col-2', {
                id: 'box-2',
                block: 'container',
                children: [],
            } as BlockNode) ?? [],
        ).document

        const { document: after } = applyPatch(
            withSecond,
            moveNode(withSecond, 'x', 'box-2', 0) ?? [],
        )

        expect(childrenOf(after)).toEqual(['y'])
        expect(
            after[0].columns?.[1].blocks?.[0].children?.map((child) => child.id),
        ).toEqual(['x'])
    })

    it('addresses a LATER sibling container against the document after the lift', () => {
        // A move is remove-then-add on both sides. `box` sits at index 1;
        // once `a` is lifted out it is at index 0, so a destination pointer
        // computed before the removal would address nothing at all.
        const document = nestedDocument()
        const operations = moveNode(document, 'a', 'box', 0)

        expect(operations).toEqual([
            { op: 'move', from: '/0/columns/0/blocks/0', path: '/0/columns/0/blocks/0/children/0' },
        ])
    })

    it('creates a missing children list before moving into it', () => {
        const document: SectionNode[] = [
            {
                id: 'sec-1',
                type: 'section',
                columns: [
                    {
                        id: 'col-1',
                        span: 12,
                        blocks: [
                            { id: 'a', block: 'heading' },
                            { id: 'box', block: 'container' },
                        ],
                    },
                ],
            },
        ]

        const { document: after } = applyPatch(document, moveNode(document, 'a', 'box', 0) ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['box'])
        expect(after[0].columns?.[0].blocks?.[0].children?.map((c) => c.id)).toEqual(['a'])
    })

    it('refuses to put a node inside itself', () => {
        expect(moveNode(nestedDocument(), 'box', 'box', 0)).toBeNull()
    })

    it('refuses to put a container inside its own descendant', () => {
        expect(moveNode(nestedDocument(), 'box', 'x', 0)).toBeNull()
    })

    it('refuses a parent that holds no blocks at all', () => {
        expect(moveNode(nestedDocument(), 'a', 'sec-1', 0)).toBeNull()
    })
})

describe('duplicating nested content', () => {
    it('duplicates a container next to itself with fresh ids throughout', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(document, duplicateNode(document, 'box') ?? [])

        const blocks = after[0].columns?.[0].blocks ?? []
        expect(blocks.map((b) => b.id.length > 0)).toEqual([true, true, true])

        const copy = blocks[2]
        expect(copy.block).toBe('container')
        expect(copy.id).not.toBe('box')
        expect((copy.children ?? []).map((child) => child.id)).not.toEqual(['x', 'y'])
        expect(copy.children).toHaveLength(2)
    })

    it('gives every node in a nested subtree a new id', () => {
        const copy = withFreshIds(nestedDocument()[0].columns?.[0].blocks?.[1] as BlockNode)

        expect(copy.id).not.toBe('box')
        expect(copy.children?.[0].id).not.toBe('x')
        expect(copy.children?.[1].id).not.toBe('y')
    })

    it('duplicates a block that sits inside a container, in place', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(document, duplicateNode(document, 'x') ?? [])

        const children = childrenOf(after)
        expect(children).toHaveLength(3)
        expect(children[0]).toBe('x')
        expect(children[2]).toBe('y')
    })
})

/**
 * Undo and redo of nested work.
 *
 * The store keeps no container-specific history: an undo step IS the
 * inverse batch `applyPatch` computes, so what these assert is that the
 * generic mechanism already restores a nested tree exactly — the same
 * claim the builder makes for every other gesture.
 */
describe('undo and redo of nested operations', () => {
    const twoBoxes = (): SectionNode[] => {
        const document = nestedDocument()
        document[0].columns?.[1].blocks?.push({ id: 'box-2', block: 'container', children: [] })

        return document
    }

    const gestures: [string, (document: SectionNode[]) => ReturnType<typeof moveNode>][] = [
        ['create a container', (d) => insertBlock(d, 'col-2', blockFrom(container))],
        ['add a child', (d) => insertBlock(d, 'box', { id: 'z', block: 'heading' })],
        ['reorder a child', (d) => moveNode(d, 'x', 'box', 2)],
        ['move a child between containers', (d) => moveNode(d, 'x', 'box-2', 0)],
        ['move a child out to a column', (d) => moveNode(d, 'x', 'col-2', 0)],
        ['move a root block into a container', (d) => moveNode(d, 'a', 'box', 0)],
        ['duplicate a container', (d) => duplicateNode(d, 'box')],
        ['duplicate a nested block', (d) => duplicateNode(d, 'y')],
        ['delete a child', (d) => removeNode(d, 'x')],
        ['delete a container', (d) => removeNode(d, 'box')],
    ]

    it.each(gestures)('undoes %s back to the exact tree', (_label, gesture) => {
        const before = twoBoxes()
        const applied = applyPatch(before, gesture(before) ?? [])

        expect(applied.document).not.toEqual(before)
        expect(applyPatch(applied.document, applied.inverse).document).toEqual(before)
    })

    it.each(gestures)('redoes %s to the exact tree', (_label, gesture) => {
        const before = twoBoxes()
        const operations = gesture(before) ?? []
        const applied = applyPatch(before, operations)
        const undone = applyPatch(applied.document, applied.inverse).document

        // Redo replays the ORIGINAL batch, which is what the store pushes.
        expect(applyPatch(undone, operations).document).toEqual(applied.document)
    })

    it('undoes a run of nested gestures one step at a time', () => {
        const before = twoBoxes()
        const steps = [
            (d: SectionNode[]) => moveNode(d, 'a', 'box', 0),
            (d: SectionNode[]) => moveNode(d, 'y', 'box-2', 0),
            (d: SectionNode[]) => removeNode(d, 'x'),
        ]

        let document: SectionNode[] = before
        const inverses: PatchOperation[][] = []
        const snapshots: SectionNode[][] = [before]

        for (const step of steps) {
            const applied = applyPatch(document, step(document) ?? [])
            inverses.push(applied.inverse)
            document = applied.document
            snapshots.push(document)
        }

        for (let i = inverses.length - 1; i >= 0; i--) {
            document = applyPatch(document, inverses[i]).document
            expect(document).toEqual(snapshots[i])
        }
    })
})

describe('removing nested content', () => {
    it('removes a block from inside a container', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(document, removeNode(document, 'x') ?? [])

        expect(childrenOf(after)).toEqual(['y'])
    })

    it('removes a container and everything in it', () => {
        const document = nestedDocument()
        const { document: after } = applyPatch(document, removeNode(document, 'box') ?? [])

        expect(after[0].columns?.[0].blocks?.map((b) => b.id)).toEqual(['a'])
    })
})
