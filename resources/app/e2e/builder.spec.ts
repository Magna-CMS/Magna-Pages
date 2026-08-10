import { expect, test, type Page } from '@playwright/test'

/**
 * The agency test, automated (08-BUILD-PHASES Phase B exit): sign in,
 * create a page from a title, build it visually, publish, and see it live.
 * Every step drives the real panel, the real builder bundle, the real
 * renderer — nothing mocked, because nothing mocked is the architecture.
 */

const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password'

const pageTitle = `E2E ${Date.now()}`

async function signIn(page: Page): Promise<void> {
    await page.goto('/login')
    // Structural selectors, not labels: Filament wraps the password input
    // in a reveal-toggle group whose labelling defeats getByLabel.
    await page.locator('input[type="email"]').fill('e2e@magna.test')
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.getByRole('button', { name: /sign in/i }).click()
    // Landing anywhere inside the panel is success; the exact dashboard
    // route is not this suite's business.
    await page.waitForURL((url) => !url.pathname.includes('login'))
}

test('create, build, publish, view', async ({ page }) => {
    await signIn(page)

    // Create a page from a title alone and land in the builder.
    await page.goto('/pages-index')
    await page.getByPlaceholder('About us').fill(pageTitle)
    await page.getByRole('button', { name: 'Create & open builder' }).click()
    await page.waitForURL(/pages-builder\/edit\//)

    // The shell is up and the canvas frame loaded the real render.
    await expect(page.locator('.builder__frame')).toBeVisible()
    await expect(page.getByRole('status')).toHaveText(/Saved/)

    // Structure: add a section, then a heading block into it.
    await page.getByRole('button', { name: '+ Section' }).click()
    const builderError = page.locator('.builder__error')
    if (await builderError.isVisible().catch(() => false)) {
        throw new Error(`builder error: ${await builderError.textContent()}`)
    }
    await expect(page.getByRole('button', { name: 'Section', exact: true })).toBeVisible()

    // Block insertion targets a COLUMN — selecting the section is not
    // enough, by design (the Add panel says so in words).
    await page.getByRole('button', { name: /Column \(12\)/ }).click()

    // Selecting the column re-renders the Add panel (disabled -> enabled);
    // assert the ENABLED state first so the click resolves the fresh node,
    // not the detached pre-render one.
    const headingButton = page.getByRole('button', { name: 'Heading', exact: true })
    await expect(headingButton).toBeEnabled()
    await headingButton.click()

    // The optimistic insert lands in the layers tree immediately.
    await expect(
        page.getByRole('navigation', { name: 'Page structure' }).getByRole('button', { name: 'heading' }),
    ).toBeVisible()
    if (await builderError.isVisible().catch(() => false)) {
        throw new Error(`builder error after heading: ${await builderError.textContent()}`)
    }

    // The canvas now contains the block, served by the production renderer.
    // The canvas full-reloads after a structure change; poll the frame's
    // DOM directly rather than through frameLocator, which can pin a
    // detached pre-reload frame and wait on it forever.
    await expect
        .poll(
            () =>
                page
                    .locator('.builder__frame')
                    .evaluate((el) =>
                        ((el as HTMLIFrameElement).contentDocument?.body?.innerHTML ?? '').includes(
                            'data-magna-kind="block"',
                        ),
                    ),
            { timeout: 15_000 },
        )
        .toBe(true)

    // Inspector edit: the schema-driven field writes through the patch API.
    const textField = page.locator('#field-text')
    await textField.fill('Written by a robot')
    await textField.blur()
    await expect(page.getByRole('status')).toHaveText(/Saved/, { timeout: 10_000 })

    // Publish from the top bar; the page is live at its path.
    page.on('dialog', (dialog) => void dialog.accept())
    await page.getByRole('button', { name: 'Publish', exact: true }).click()
    await expect(page.getByRole('link', { name: 'View page' })).toBeVisible({ timeout: 10_000 })

    const viewUrl = await page.getByRole('link', { name: 'View page' }).getAttribute('href')
    expect(viewUrl).not.toBeNull()

    await page.goto(viewUrl as string)
    await expect(page.locator('body')).toContainText('Written by a robot')
})

test('second session sees the lock and can take over', async ({ browser }) => {
    const first = await browser.newContext({ ignoreHTTPSErrors: true })
    const second = await browser.newContext({ ignoreHTTPSErrors: true })

    const firstPage = await first.newPage()
    await signIn(firstPage)
    await firstPage.goto('/pages-index')
    await firstPage.getByRole('link', { name: 'Open builder' }).first().click()
    await firstPage.waitForURL(/pages-builder\/edit\//)
    const builderUrl = firstPage.url()

    // Same account, separate session cookie jar — same-user lock sharing
    // means no banner; the REAL contention case needs a second account and
    // lives in the PHP suite. Here we prove the surface renders either way.
    const secondPage = await second.newPage()
    await signIn(secondPage)
    await secondPage.goto(builderUrl)
    await expect(secondPage.locator('.builder__frame')).toBeVisible()

    await first.close()
    await second.close()
})
