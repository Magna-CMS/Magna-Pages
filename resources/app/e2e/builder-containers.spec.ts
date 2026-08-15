import { expect, test, type Page } from '@playwright/test'

/**
 * Nesting, end to end (12-BUILDER-REDESIGN §15).
 *
 * The unit suites prove the producers emit the right patch and the drop
 * geometry picks the right parent. What only a real browser can prove is
 * that the two meet: that a container reports a box the drag can aim at,
 * that the canvas re-render puts children back INSIDE it, and that the
 * tree survives a reload rather than only surviving the store.
 *
 * Structure is driven through the navigator wherever a navigator path
 * exists — it is the guaranteed keyboard path (ATAG 2.0), so if a gesture
 * works there it works for everyone — plus two real canvas drags, because
 * dropping into a container is the gesture the whole step exists for and
 * nothing else exercises the geometry.
 *
 * Container → root is deliberately NOT driven here: aiming at a column's
 * bare area is a fixture about how tall the theme's boxes happen to be,
 * not about nesting. That direction is covered where it can be asserted
 * exactly — the `moveNode` specs in document/edits.test.ts and the API
 * round trip in PagesContainerBlockTest.
 */

const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password'

async function signIn(page: Page): Promise<void> {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill('e2e@magna.test')
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.getByRole('button', { name: /sign in/i }).click()
    await page.waitForURL((url) => !url.pathname.includes('login'))
}

async function newBuilderPage(page: Page, title: string): Promise<string> {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page)

    await page.goto('/pages-index')
    await page.getByPlaceholder('About us').fill(`${title} ${Date.now()}`)
    await page.getByRole('button', { name: 'Create & open builder' }).click()
    await page.waitForURL(/pages-builder\/edit\//)
    await expect(page.locator('.builder__frame')).toBeVisible()
    await expect(page.getByRole('status')).toHaveText(/Saved/)

    return page.url()
}

const navigator = (page: Page) => page.getByRole('navigation', { name: 'Page structure' })

/** Open the navigator drawer and leave it open — every structural step uses it. */
async function openNavigator(page: Page): Promise<void> {
    if (await navigator(page).isVisible().catch(() => false)) {
        return
    }
    await page.getByRole('button', { name: 'Navigator' }).click()
    await expect(navigator(page)).toBeVisible()
}

async function saved(page: Page): Promise<void> {
    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 20_000 })
}

/** Click a navigator row by its label, selecting that node. */
async function selectInNavigator(page: Page, name: string, index = 0): Promise<void> {
    await navigator(page).getByRole('button', { name, exact: true }).nth(index).click()
}

/** Run one of a node's actions from its navigator row menu. */
async function actOn(page: Page, nodeLabel: string, index: number, action: string): Promise<void> {
    await navigator(page)
        .getByRole('button', { name: `Actions for this ${nodeLabel}` })
        .nth(index)
        .click()
    await page.getByRole('menuitem', { name: action, exact: true }).click()
}

/**
 * Add an element from the library into whatever is currently selected.
 *
 * Placing an element switches the panel to Edit — a placed element is one
 * the editor wants to fill in next — so every add starts by asking for the
 * Add tab back.
 */
async function addElement(page: Page, label: string): Promise<void> {
    await page.getByRole('tab', { name: 'Add' }).click()

    const button = page.getByRole('button', { name: label, exact: true })
    await expect(button).toBeEnabled()
    await button.click()
    await saved(page)
}

/**
 * The document as the CANVAS shows it: each container's node id with the
 * node ids it holds. Read from the frame rather than from the store, so
 * what is asserted is what the server rendered.
 */
async function canvasTree(page: Page): Promise<Record<string, string[]>> {
    return page.locator('.builder__frame').evaluate((el) => {
        const doc = (el as HTMLIFrameElement).contentDocument
        const tree: Record<string, string[]> = {}
        if (!doc) {
            return tree
        }

        const nodeId = (node: Element): string => node.getAttribute('data-magna-node') ?? ''
        const record = (key: string, scope: Element): void => {
            tree[key] = [...scope.children]
                .filter((child) => child.hasAttribute('data-magna-node'))
                .map(nodeId)
        }

        doc.querySelectorAll('[data-magna-kind="column"]').forEach((column) =>
            record(nodeId(column), column),
        )
        doc.querySelectorAll('.magna-container').forEach((container) =>
            record(nodeId(container), container),
        )

        return tree
    })
}

/**
 * The canvas tree, once it satisfies `ready`.
 *
 * Every structural edit full-reloads the frame, and "Saved" is reported
 * before that reload finishes — so a single read races the old document.
 * Polling here rather than at each call site is what keeps the test about
 * nesting instead of about timing.
 */
async function treeWhen(
    page: Page,
    ready: (tree: Record<string, string[]>) => boolean,
): Promise<Record<string, string[]>> {
    let tree: Record<string, string[]> = {}

    await expect
        .poll(
            async () => {
                tree = await canvasTree(page)

                return ready(tree)
            },
            { timeout: 25_000 },
        )
        .toBe(true)

    return tree
}

/** Wait until the canvas holds the expected number of containers. */
async function waitForContainers(page: Page, count: number): Promise<void> {
    await expect
        .poll(
            () =>
                page
                    .locator('.builder__frame')
                    .evaluate(
                        (el) =>
                            (el as HTMLIFrameElement).contentDocument?.querySelectorAll(
                                '.magna-container',
                            ).length ?? 0,
                    ),
            { timeout: 25_000 },
        )
        .toBe(count)
}

/** The centre of a canvas element, in the parent document's coordinates. */
async function centreOf(page: Page, selector: string, index: number) {
    const spot = await page.locator('.builder__frame').evaluate(
        (el, args) => {
            const node = (el as HTMLIFrameElement).contentDocument?.querySelectorAll(args.selector)[
                args.index
            ]
            const box = node?.getBoundingClientRect()

            return box ? { x: box.left + box.width / 2, y: box.top + box.height / 2 } : null
        },
        { selector, index },
    )

    const stage = await page.locator('.builder__stage').boundingBox()
    if (!spot || !stage) {
        throw new Error(`no geometry for ${selector}[${index}]`)
    }

    return { x: stage.x + spot.x, y: stage.y + spot.y }
}

test('builds, rearranges and reloads a nested document', async ({ page }) => {
    const builderUrl = await newBuilderPage(page, 'Nesting')

    // ── Create a container, and fill it ──────────────────────────────────
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    await addElement(page, 'Container')
    await waitForContainers(page, 1)

    // The container is selected after insertion, so the next element goes
    // INSIDE it — and the one after that joins its sibling, because a
    // selected child targets whatever holds it.
    await addElement(page, 'Heading')
    await addElement(page, 'Text')

    await openNavigator(page)
    await expect(navigator(page).getByRole('button', { name: 'container', exact: true })).toHaveCount(1)

    // Exactly one holder has two children — the container just filled.
    let tree = await treeWhen(page, (t) => Object.values(t).some((kids) => kids.length === 2))
    const containerId = Object.keys(tree).find((id) => tree[id].length === 2) as string
    const columnId = Object.keys(tree).find((id) => tree[id].includes(containerId)) as string

    expect(containerId).toBeTruthy()
    expect(columnId).toBeTruthy()

    const [firstChild, secondChild] = tree[containerId]

    // ── Reorder inside the container ─────────────────────────────────────
    await actOn(page, 'heading', 0, 'Move down')
    await saved(page)
    await treeWhen(page, (t) => t[containerId]?.[0] === secondChild && t[containerId]?.[1] === firstChild)

    // ── A second container, and a block at the root ──────────────────────
    await selectInNavigator(page, 'Column (12)')
    await addElement(page, 'Container')
    await waitForContainers(page, 2)

    await selectInNavigator(page, 'Column (12)')
    await addElement(page, 'Divider')

    tree = await treeWhen(page, (t) => (t[columnId]?.length ?? 0) === 3)

    const secondContainer = tree[columnId].find(
        (id) => id !== containerId && holdsBlocks(tree, id),
    ) as string
    const rootBlock = tree[columnId].find(
        (id) => id !== containerId && id !== secondContainer,
    ) as string

    expect(secondContainer).toBeTruthy()
    expect(rootBlock).toBeTruthy()

    // ── Drag the root block into the second container ────────────────────
    // The one gesture no unit test can stand in for: an empty container has
    // to report a box the drag can aim at.
    const source = await centreOf(page, `[data-magna-node="${rootBlock}"]`, 0)
    const target = await centreOf(page, `[data-magna-node="${secondContainer}"]`, 0)

    await page.mouse.move(source.x, source.y)
    await page.mouse.down()
    await page.mouse.move(source.x + 20, source.y + 20, { steps: 5 })
    await page.mouse.move(target.x, target.y, { steps: 10 })
    await expect(page.locator('.builder__drop')).toBeVisible()
    await page.mouse.up()
    await saved(page)

    // Moved, not re-created: the block keeps the id it had at the root.
    await treeWhen(page, (t) => (t[secondContainer] ?? []).join() === rootBlock)

    // ── Move it on again, container to container ─────────────────────────
    const intoFirst = await centreOf(page, `[data-magna-node="${containerId}"]`, 0)
    const nested = await centreOf(page, `[data-magna-node="${rootBlock}"]`, 0)

    await page.mouse.move(nested.x, nested.y)
    await page.mouse.down()
    await page.mouse.move(nested.x + 20, nested.y + 20, { steps: 5 })
    await page.mouse.move(intoFirst.x, intoFirst.y, { steps: 10 })
    await expect(page.locator('.builder__drop')).toBeVisible()
    await page.mouse.up()
    await saved(page)

    await treeWhen(
        page,
        (t) => (t[secondContainer] ?? []).length === 0 && (t[containerId] ?? []).includes(rootBlock),
    )

    // ── Duplicate, then delete, nested content ───────────────────────────
    await actOn(page, 'divider', 0, 'Duplicate')
    await saved(page)
    await treeWhen(page, (t) => (t[containerId] ?? []).length === 4)

    await actOn(page, 'divider', 1, 'Delete')
    await saved(page)
    await treeWhen(page, (t) => (t[containerId] ?? []).length === 3)

    // ── Undo, then redo ──────────────────────────────────────────────────
    await page.getByRole('button', { name: 'Undo', exact: true }).click()
    await treeWhen(page, (t) => (t[containerId] ?? []).length === 4)

    await page.getByRole('button', { name: 'Redo', exact: true }).click()
    const before = await treeWhen(page, (t) => (t[containerId] ?? []).length === 3)

    // ── Reload: the tree the server stored is the tree that comes back ───
    await page.goto(builderUrl)
    await expect(page.locator('.builder__frame')).toBeVisible()
    await waitForContainers(page, 2)

    await expect.poll(() => canvasTree(page), { timeout: 25_000 }).toEqual(before)
})

test('nests and unnests a block from the navigator alone', async ({ page }) => {
    await newBuilderPage(page, 'Nesting by keyboard')

    // Dragging is not everyone's input device. Everything below goes
    // through the navigator's own menu, which is the guaranteed editing
    // path — if nesting is unreachable here it is unreachable for anyone
    // who does not use a mouse.
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    await addElement(page, 'Container')
    await waitForContainers(page, 1)

    // A sibling AFTER the container, so the container is the thing above it.
    await openNavigator(page)
    await selectInNavigator(page, 'Column (12)')
    await addElement(page, 'Divider')

    const tree = await treeWhen(page, (t) => Object.values(t).some((kids) => kids.length === 2))
    const columnId = Object.keys(tree).find((id) => tree[id].length === 2) as string
    const [containerId, blockId] = tree[columnId]

    await actOn(page, 'divider', 0, 'Move into')
    await saved(page)
    await treeWhen(page, (t) => (t[containerId] ?? []).join() === blockId)

    await actOn(page, 'divider', 0, 'Move out')
    await saved(page)

    // Back beside the container, and still the same node — a move, not a
    // re-creation.
    await treeWhen(
        page,
        (t) => (t[containerId] ?? []).length === 0 && t[columnId]?.join() === `${containerId},${blockId}`,
    )
})

/** Whether the tree knows this id as a holder — i.e. it is a container. */
function holdsBlocks(tree: Record<string, string[]>, id: string): boolean {
    return tree[id] !== undefined
}
