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

test('opens every dock drawer, including the ones fetched on demand', async ({ page }) => {
    await newBuilderPage(page, 'Drawers')

    // Design, Checks, History and Comments are loaded the first time they
    // are opened, so a broken dynamic import shows up as a drawer that
    // opens onto nothing — which no other spec would catch.
    for (const [tab, heading] of [
        ['Design', 'Design'],
        ['Checks', 'Checks'],
        ['History', 'History'],
        ['Comments', 'Comments'],
    ]) {
        await page.getByRole('button', { name: tab, exact: true }).click()
        await expect(
            page.getByRole('region').getByRole('heading', { name: heading, exact: true }),
        ).toBeVisible({ timeout: 15_000 })
    }

    // The navigator stays eager: it is the guaranteed keyboard path.
    await page.getByRole('button', { name: 'Navigator', exact: true }).click()
    await expect(page.getByRole('navigation', { name: 'Page structure' })).toBeVisible()
})

test('announces a panel mode swap and gives its tabs a panel to control', async ({ page }) => {
    await newBuilderPage(page, 'Panel a11y')

    const panel = page.locator('#panel-body')

    // A tab that controls nothing is announced as a tab that controls
    // nothing — the tablist needs a tabpanel, and it needs to say which
    // tab is filling it.
    await expect(panel).toHaveAttribute('role', 'tabpanel')
    await expect(panel).toHaveAttribute('aria-labelledby', 'panel-mode-library')
    await expect(page.locator('#library-panel')).toHaveAttribute(
        'aria-labelledby',
        'library-tab-elements',
    )

    await page.getByRole('tab', { name: 'Edit' }).click()
    await expect(panel).toHaveAttribute('aria-labelledby', 'panel-mode-inspect')

    // Swapping mode replaces everything in the panel. Without moving focus
    // and saying so, a screen reader has no way to notice.
    await expect
        .poll(() => page.evaluate(() => document.activeElement?.id))
        .toBe('panel-body')

    await expect(page.locator('[aria-live="polite"]').first()).toContainText('Edit panel')
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

/**
 * Typing on the page itself.
 *
 * Inline editing existed before this test and yet everyone still used the
 * panel, because three things stood between the gesture and the words: a
 * dead second handler in the bridge answered the "make this editable"
 * message first and refused every block whose marked node is a WRAPPER
 * (which is nearly all of them); the canvas swallowed the very clicks that
 * place a caret; and the tag picker in the inspector, sized for a layout it
 * was not in, squeezed the Text field down to a sliver so the panel looked
 * broken too. All three are asserted here.
 */
test('edits a heading on the page, by both gestures', async ({ page }) => {
    await newBuilderPage(page, 'Inline')

    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    const frame = page.frameLocator('.builder__frame')
    await frame.locator('[data-magna-kind="column"]').first().click()
    // Selecting a column flips the panel to Edit; Add is where blocks live.
    await page.getByRole('tab', { name: 'Add', exact: true }).click()
    await page.getByRole('button', { name: 'Heading', exact: true }).click()
    await waitForNodes(page, 'block', 1)

    // The inspector's Text field is a field, not a sliver: one class used
    // in two layouts is what collapsed it.
    const field = page.locator('#field-text')
    await expect(field).toBeVisible()
    expect((await field.boundingBox())!.width).toBeGreaterThan(150)

    // A double-click means "replace these words". The editable element is
    // the h2 INSIDE the marked wrapper, which is the whole reason this
    // used to refuse.
    const heading = frame.locator('[data-magna-kind="block"]').first()
    await heading.dblclick()
    await expect(heading.locator('[contenteditable]')).toHaveCount(1)
    await page.keyboard.type('Typed on the page')
    await page.keyboard.press('Enter')
    await expect(heading).toHaveText('Typed on the page')

    // It reached the document, not only the pixels.
    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 15_000 })
    await expect(field).toHaveValue('Typed on the page')

    // The toolbar carries the gesture as a visible affordance, so nobody
    // has to know the gesture exists.
    await expect(page.getByRole('button', { name: 'Edit text in place' })).toBeVisible()

    // A second click on what is already selected types at the caret rather
    // than replacing everything.
    await heading.click()
    await heading.click()
    await expect(heading.locator('[contenteditable]')).toHaveCount(1)
    await page.keyboard.type('!')
    await page.keyboard.press('Enter')
    await expect(heading).toHaveText(/Typed on the page/)
})

/**
 * The canvas may not navigate.
 *
 * Containment used to apply only to events landing inside a MARKED node,
 * and everything else kept native behaviour. The header, the footer and
 * all theme chrome render unmarked, so clicking a header link walked the
 * iframe off the page being edited and left the builder pointing at
 * nothing — a blank canvas with no way back but a reload.
 */
test('never follows a link out of the canvas', async ({ page }) => {
    await newBuilderPage(page, 'Chrome')
    // Give the page a node, so "the canvas survived" is something the DOM
    // can actually answer.
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    await waitForNodes(page, 'section', 1)
    const frame = page.frameLocator('.builder__frame')

    // The canvas frame's own location — the parent URL never changes when
    // a link inside the frame is followed, so asserting on it would pass
    // whether or not this bug exists.
    const canvasUrl = () =>
        page
            .locator('.builder__frame')
            .evaluate((el) => (el as HTMLIFrameElement).contentWindow?.location.href ?? '')

    const before = await canvasUrl()

    // Not a fragment link: a link that leaves the page is the case that
    // strands the editor on a blank canvas.
    const link = frame.locator('a[href]:not([href^="#"])').first()
    await expect(link).toBeVisible()
    const href = await link.getAttribute('href')
    expect(href).toBeTruthy()
    expect(new URL(href!, before).href).not.toBe(before)

    // Dispatched rather than aimed: the canvas frame is drawn at full page
    // height under the builder's own overlay, so a mouse press at the
    // link's coordinates is not reliably delivered to it. A dispatched
    // click still bubbles through the capture listener under test and
    // still runs the anchor's default action, which is the behaviour in
    // question.
    await link.dispatchEvent('click')
    await page.waitForTimeout(1200)

    // The canvas is still the page being edited, with its nodes intact.
    expect(await canvasUrl()).toBe(before)
    await expect(frame.locator('[data-magna-node]').first()).toBeVisible()

    // Keyboard activation is the same navigation by another route.
    await link.focus()
    await page.keyboard.press('Enter')
    await page.waitForTimeout(1000)
    expect(await canvasUrl()).toBe(before)
})

/**
 * The Add panel draws elements, not a list of words.
 *
 * A block definition has always named an icon and nothing drew it, so the
 * panel was a column of text rows. The count assertion below is the part
 * that matters over time: every installed block — core, first-party
 * plugin, or third party — must resolve to real geometry, and a block
 * whose icon name this install does not know falls back to a dashed
 * circle rather than an empty box. Zero fallbacks means the shipped
 * vocabulary actually covers the shipped blocks.
 */
test('offers every element as a tile with a real icon', async ({ page }) => {
    await newBuilderPage(page, 'Panel')
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()

    const tiles = page.locator('.library__tile')
    await expect(tiles.first()).toBeVisible()
    expect(await tiles.count()).toBeGreaterThan(15)

    // Three across at the panel's usual width: the tiles sit in rows, not
    // in one column, which is the whole point of the redesign.
    const first = (await tiles.nth(0).boundingBox())!
    const second = (await tiles.nth(1).boundingBox())!
    expect(second.y).toBe(first.y)
    expect(second.x).toBeGreaterThan(first.x)

    const fallbacks = await page.locator('.library__tile .bicon circle[stroke-dasharray]').count()
    expect(fallbacks).toBe(0)

    // The tile still adds the block it names.
    await page.locator('.builder__frame').contentFrame().locator('[data-magna-kind="column"]').first().click()
    await page.getByRole('tab', { name: 'Add', exact: true }).click()
    await page.getByRole('button', { name: 'Icon', exact: true }).click()
    await waitForNodes(page, 'block', 1)
    const frame = page.locator('.builder__frame').contentFrame()
    await expect(frame.locator('.magna-block--icon svg')).toBeVisible()

    // The icon field is a picker, not a text box asking an editor to type
    // `core:chevron-right` from memory.
    await page.locator('#field-name').click()
    // Scoped to the picker: every <select> in the inspector also has
    // options, and role alone would count those too.
    const options = page.locator('.iconpick__option')
    await expect(options.first()).toBeVisible()
    await page.getByPlaceholder('Search icons…').fill('heart')
    await expect(options).toHaveCount(1)
    await options.first().click()

    // Picking one writes it to the document and redraws the canvas.
    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 15_000 })
    await expect(frame.locator('.magna-block--icon svg')).toBeVisible()
})

/**
 * The page is a thing with settings.
 *
 * Nothing-selected used to mean nothing to edit. The ground a document is
 * drawn on is real, and this asserts the whole round trip: the panel
 * offers the page vocabulary, a change reaches the server, and the canvas
 * comes back painted.
 */
test('paints the page from the panel with nothing selected', async ({ page }) => {
    await newBuilderPage(page, 'Ground')
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    await waitForNodes(page, 'section', 1)

    // Deselect: the page is what the inspector shows when no node does.
    await page.keyboard.press('Escape')
    // The tab's name carries the selection label after it, so match on
    // the id rather than on a name that changes with the selection.
    await page.locator('#panel-mode-inspect').click()
    await expect(page.locator('.inspector__block')).toHaveText('Page')

    const background = page.locator('#style-background')
    await expect(background).toBeVisible()
    await background.fill('#123456')
    await background.dispatchEvent('change')

    // It reached the canvas, which means it reached the server and came
    // back through a real render.
    await expect
        .poll(
            () =>
                page
                    .locator('.builder__frame')
                    .evaluate(
                        (el) =>
                            (el as HTMLIFrameElement).contentDocument?.body?.innerHTML ?? '',
                    ),
            { timeout: 20_000 },
        )
        .toContain('background-color:#123456')
})

/**
 * The builder's scrollbars are the builder's.
 *
 * The trap this guards is specific: setting EITHER standard property makes
 * Chromium ignore ::-webkit-scrollbar entirely, so a stylesheet carrying
 * both silently keeps the platform default. Asserting the computed value
 * catches that, where a screenshot of a thin dark bar would not.
 */
test('styles its own scrollbars', async ({ page }) => {
    await newBuilderPage(page, 'Scroll')
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()

    const panel = page.locator('.panel__body')
    const style = await panel.evaluate((el) => ({
        color: getComputedStyle(el).scrollbarColor,
        width: getComputedStyle(el).scrollbarWidth,
        gutter: el.offsetWidth - el.clientWidth,
    }))

    expect(style.width).toBe('thin')
    // The track is transparent and the thumb is the builder's own colour.
    expect(style.color).toContain('rgba(0, 0, 0, 0)')
    expect(style.color).not.toContain('auto')
    // Reserved whether or not anything overflows, so expanding a category
    // does not shove the whole panel sideways.
    expect(style.gutter).toBeGreaterThan(0)
})

/**
 * Designing for dark without being able to see it is designing blind.
 *
 * The canvas already carries both readings of the palette in one
 * stylesheet, so previewing is a matter of choosing which one shows —
 * no re-render, no second document, and nothing written to this page.
 */
test('previews the page in both colour schemes', async ({ page }) => {
    await newBuilderPage(page, 'Scheme')
    await page.getByRole('button', { name: 'Add section: 1 column' }).click()
    await waitForNodes(page, 'section', 1)

    const root = () =>
        page
            .locator('.builder__frame')
            .evaluate((el) => {
                const document_ = (el as HTMLIFrameElement).contentDocument
                const html = document_?.documentElement

                return {
                    theme: html?.getAttribute('data-theme') ?? null,
                    background: html
                        ? getComputedStyle(document_!.body).backgroundColor
                        : '',
                }
            })

    const light = await root()
    expect(light.theme).toBeNull()

    await page.getByRole('group', { name: 'Preview colour scheme' })
        .getByRole('button', { name: 'dark' })
        .click()

    await expect.poll(async () => (await root()).theme, { timeout: 10_000 }).toBe('dark')

    // The palette actually changed, which is the point — the attribute on
    // its own would prove only that a button was wired up.
    const dark = await root()
    expect(dark.background).not.toBe(light.background)

    // And it survives a canvas reload: a reload is a new document, and the
    // attribute lived in the old one.
    await page.getByRole('button', { name: 'Add section: 50 / 50' }).click()
    await waitForNodes(page, 'section', 2)
    await expect.poll(async () => (await root()).theme, { timeout: 10_000 }).toBe('dark')
})

/**
 * Sticky chrome sticks the element the THEME wrapped it in.
 *
 * This is the one part of the header work that correct-looking CSS can get
 * wrong invisibly. A theme puts our chrome inside its own <header>, and an
 * element can only stick within its parent's box — so a rule naming only
 * our wrapper would emit exactly the CSS we intended and do nothing at
 * all, because the wrapper is precisely as tall as its parent.
 *
 * Rendered against the real structure and the real emitted rule, then
 * scrolled, because where the header ends up is the only thing that can
 * tell those two cases apart.
 */
test('sticks chrome to the top of the page it is on', async ({ page }) => {
    const rule =
        '.magna-chrome--sticky,:where(header,footer,div,section):has(>.magna-chrome--sticky)' +
        '{position:sticky;top:0;z-index:50}'

    await page.setViewportSize({ width: 900, height: 600 })
    await page.setContent(`
        <style>body{margin:0}${rule}</style>
        <header class="l-header l-header--custom">
            <div class="magna-chrome magna-chrome--header magna-chrome--sticky">
                <div style="height:60px;background:#123">Sticky header</div>
            </div>
        </header>
        <main style="height:4000px">Long page</main>
    `)

    const top = () =>
        page.locator('header.l-header').evaluate((el) => el.getBoundingClientRect().top)

    expect(await top()).toBe(0)

    await page.evaluate(() => window.scrollTo(0, 1500))
    await page.waitForTimeout(200)

    // Still at the top of the viewport after scrolling past it.
    expect(await top()).toBe(0)

    // And the control: the same markup without the sticky class scrolls
    // away, which is what proves the rule is doing the work.
    await page.setContent(`
        <style>body{margin:0}${rule}</style>
        <header class="l-header l-header--custom">
            <div class="magna-chrome magna-chrome--header">
                <div style="height:60px;background:#123">Plain header</div>
            </div>
        </header>
        <main style="height:4000px">Long page</main>
    `)

    await page.evaluate(() => window.scrollTo(0, 1500))
    await page.waitForTimeout(200)
    expect(await top()).toBeLessThan(-100)
})
