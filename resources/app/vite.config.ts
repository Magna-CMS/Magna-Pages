import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

/**
 * The builder is served by the plugin itself, from /pages-builder/app.
 *
 * Output goes to the plugin's public/builder directory and is committed, so a
 * Magna install never needs Node to run the builder — the same arrangement the
 * other plugin SPAs in this ecosystem use.
 *
 * No PWA here, deliberately. A service worker at this scope would cache an
 * admin surface that edits live content, and the builder is useless offline
 * anyway: every meaningful action needs the server (fragment renders, patch
 * authorization, saves).
 */
export default defineConfig({
    base: '/pages-builder/app/',

    plugins: [vue()],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },

    build: {
        outDir: fileURLToPath(new URL('../../public/builder', import.meta.url)),
        emptyOutDir: true,
    },
})
