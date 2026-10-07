import { test, type Page } from '@playwright/test'

/**
 * The README's screenshots, captured from a real install.
 *
 * Not a test — it asserts nothing and is excluded from the suite by its
 * `@screenshots` tag. It exists so the pictures in the README are the real
 * builder rather than a mockup someone drew, and so refreshing them after a
 * UI change is one command instead of an afternoon:
 *
 *   php artisan magna:pages:e2e-user
 *   npx playwright test e2e/screenshots.spec.ts --grep @screenshots
 *
 * E2E_SHOT_PAGE picks which page to photograph; the default is the demo
 * page that ships with the marketing seed.
 */

const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password'
const SHOT_PAGE = process.env.E2E_SHOT_PAGE ?? '01m0zb3rbfzqngmqredcc8x15f'
const OUT = '../../.github/screenshots'

async function signIn(page: Page): Promise<void> {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill('e2e@magna.test')
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.getByRole('button', { name: /sign in/i }).click()
    await page.waitForURL((url) => !url.pathname.includes('login'))
}

/** The canvas is a full page render in an iframe; give it time to land. */
async function openBuilder(page: Page): Promise<void> {
    await page.goto(`/pages-builder/edit/${SHOT_PAGE}`)
    await page.locator('iframe').first().waitFor({ state: 'visible' })
    await page.waitForTimeout(4000)
}

test.describe('@screenshots', () => {
    test.use({ viewport: { width: 1600, height: 1000 } })

    test('the builder, with the element library open', async ({ page }) => {
        await signIn(page)
        await openBuilder(page)

        await page.screenshot({ path: `${OUT}/builder-canvas.png` })
    })

    test('the inspector, editing a section', async ({ page }) => {
        await signIn(page)
        await openBuilder(page)

        // Inside <main>, because the first node on the page is the site
        // header — a template part, and clicking it puts the builder into
        // "editing the header" rather than editing this page.
        const frame = page.frameLocator('iframe').first()
        await frame.locator('main [data-magna-node]').first().click()
        await page.waitForTimeout(2000)

        await page.screenshot({ path: `${OUT}/builder-inspector.png` })
    })

    test('the page as a visitor gets it', async ({ page }) => {
        await signIn(page)
        await page.goto('/page-builder')
        await page.waitForTimeout(2000)

        await page.screenshot({ path: `${OUT}/rendered-page.png` })
    })
})
