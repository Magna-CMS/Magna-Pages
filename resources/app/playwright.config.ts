import { defineConfig } from '@playwright/test'

/**
 * End-to-end suite for the builder (11-TESTING-AND-DX §1).
 *
 * Runs against a live install (Herd locally, the app under CI) rather than
 * a mocked server — the entire point of the one-renderer architecture is
 * that the browser sees production behavior, so the E2E suite must too.
 *
 * Setup before running:
 *   php artisan magna:pages:e2e-user
 * Base URL and credentials via env for CI: E2E_BASE_URL, E2E_PASSWORD.
 */
export default defineConfig({
    testDir: './e2e',
    // The build-and-publish flow is a dozen real round trips plus three
    // canvas full-reloads. On an unwarmed local install that is comfortably
    // past 30s, and a budget that tight fails on the machine rather than on
    // the code — which is the one thing an E2E suite must never do.
    timeout: 120_000,
    retries: 1,
    workers: 1, // one document lock, one session — parallel runs would fight it
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://magna-cms.test',
        ignoreHTTPSErrors: true, // Herd's local certificate
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
})
