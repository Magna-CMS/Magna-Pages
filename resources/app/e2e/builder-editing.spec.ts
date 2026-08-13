import { expect, test, type Page } from '@playwright/test'

/**
 * The two gestures the redesign added and the original suite does not
 * cover: dragging an element from the panel into a chosen column, and
 * editing rich text where the text is.
 *
 * Both are here because both broke in ways nothing else would have
 * caught — an empty column reported a zero-height rect so no drop target
 * could ever be found in it, and the canvas bridge announced itself too
 * late for the parent to have any geometry at all. Neither shows up in a
 * unit test: they are properties of the real page in the real frame.
 */

const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password'

async function signIn(page: Page): Promise<void> {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill('e2e@magna.test')
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.getByRole('button', { name: /sign in/i }).click()
    await page.waitForURL((url) => !url.pathname.includes('login'))
}

/** A fresh page, open in the builder, with the canvas up. */
async function newBuilderPage(page: Page, title: string): Promise<void> {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page)

    await page.goto('/pages-index')
    await page.getByPlaceholder('About us').fill(`${title} ${Date.now()}`)
    await page.getByRole('button', { name: 'Create & open builder' }).click()
    await page.waitForURL(/pages-builder\/edit\//)
    await expect(page.locator('.builder__frame')).toBeVisible()
    await expect(page.getByRole('status')).toHaveText(/Saved/)
}

/** Wait until the canvas has rendered the expected number of nodes. */
async function waitForNodes(page: Page, kind: string, count: number): Promise<void> {
    await expect
        .poll(
            () =>
                page
                    .locator('.builder__frame')
                    .evaluate(
                        (el, selector) =>
                            (el as HTMLIFrameElement).contentDocument?.querySelectorAll(selector)
                                .length ?? 0,
                        `[data-magna-kind="${kind}"]`,
                    ),
            { timeout: 25_000 },
        )
        .toBe(count)
}

/** The centre of a canvas node, in the parent's coordinates. */
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

test('drags an element from the panel into the column it was dropped on', async ({ page }) => {
    await newBuilderPage(page, 'Drag')

    // A two-column row, so the drop has to CHOOSE — landing anywhere would
    // pass a one-column test whether or not the aim works.
    await page.getByRole('button', { name: 'Add section: 50 / 50' }).click()
    await waitForNodes(page, 'column', 2)

    const card = await page.getByRole('button', { name: 'Heading', exact: true }).boundingBox()
    if (!card) {
        throw new Error('no element card')
    }

    const target = await centreOf(page, '[data-magna-kind="column"]', 1)

    await page.mouse.move(card.x + card.width / 2, card.y + card.height / 2)
    await page.mouse.down()
    // Past the threshold inside the panel, then across into the canvas.
    await page.mouse.move(card.x + 60, card.y + 40, { steps: 5 })
    await page.mouse.move(target.x, target.y, { steps: 10 })
    await expect(page.locator('.builder__drop')).toBeVisible()
    await page.mouse.up()

    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 20_000 })
    await waitForNodes(page, 'block', 1)

    // It landed in the SECOND column, which is where the line was drawn.
    const inSecondColumn = await page.locator('.builder__frame').evaluate((el) => {
        const columns = (el as HTMLIFrameElement).contentDocument?.querySelectorAll(
            '[data-magna-kind="column"]',
        )

        return columns?.[1]?.querySelector('[data-magna-kind="block"]') !== null
    })

    expect(inSecondColumn).toBe(true)
})

test('edits rich text on the canvas and stores the markup', async ({ page }) => {
    await newBuilderPage(page, 'Rich')

    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    const textButton = page.getByRole('button', { name: 'Text', exact: true })
    await expect(textButton).toBeEnabled()
    await textButton.click()
    await waitForNodes(page, 'block', 1)

    const block = await centreOf(page, '[data-magna-kind="block"]', 0)
    await page.mouse.dblclick(block.x, block.y)

    await expect(page.getByRole('toolbar', { name: 'Text formatting' })).toBeVisible({
        timeout: 15_000,
    })
    // The toolbar enables once TipTap has loaded and taken focus; typing
    // before that goes nowhere.
    await expect(page.getByRole('button', { name: 'Bold' })).toBeEnabled({ timeout: 20_000 })

    await page.keyboard.press('Control+a')
    await page.keyboard.type('Bolded by a robot')
    await page.keyboard.press('Shift+Home')
    await page.getByRole('button', { name: 'Bold' }).click()
    await page.getByRole('button', { name: 'Done' }).click()

    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 20_000 })

    // The canvas re-renders from what the SERVER stored, so this asserts the
    // markup survived the sanitizer — not that the client kept its own copy.
    await expect
        .poll(
            () =>
                page
                    .locator('.builder__frame')
                    .evaluate(
                        (el) => (el as HTMLIFrameElement).contentDocument?.body?.innerHTML ?? '',
                    ),
            { timeout: 20_000 },
        )
        .toMatch(/<strong>Bolded by a robot<\/strong>|<b>Bolded by a robot<\/b>/)
})
