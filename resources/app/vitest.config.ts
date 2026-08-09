import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vitest/config'

/**
 * Kept apart from vite.config.ts: the build config is typed as a Vite
 * config, and folding test options into it only typechecks when Vitest's
 * types are loaded — which the production build has no reason to do.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['src/**/*.test.ts'],
    },
})
