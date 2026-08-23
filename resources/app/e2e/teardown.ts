import { execSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Delete the fixtures this run created.
 *
 * The suite runs against a LIVE install by design — the whole point of the
 * one-renderer architecture is that the browser sees production behaviour,
 * so the tests must too. The cost is that every run leaves real pages in a
 * real database, and they accumulate: an install used for development had
 * 428 of them, which is what pushed the templates section off the bottom of
 * the Pages screen and made a header look impossible to create.
 *
 * Fixture pages are named with a millisecond timestamp, so they are
 * identifiable without guessing. Nothing else is touched, and a slug that
 * does not end in one is never a candidate.
 */
async function globalTeardown(): Promise<void> {
    if (process.env.E2E_KEEP_FIXTURES === '1') {
        return
    }

    /*
     * Found rather than counted. This file sits six directories below the
     * application root, and a hardcoded `../../../../../..` is both
     * unreadable and wrong the moment anything moves — as it was on the
     * first attempt.
     */
    let root = dirname(fileURLToPath(import.meta.url))
    while (!existsSync(join(root, 'artisan'))) {
        const up = resolve(root, '..')
        if (up === root) {
            return // Not inside an application; nothing to prune.
        }
        root = up
    }

    try {
        /*
         * Through a shell, because on Windows the PHP on PATH is a .bat
         * shim that spawn cannot execute directly — without a shell this
         * fails with ENOENT on the machine that most needs the cleanup.
         *
         * One command string rather than a shell plus an args array: the
         * arguments are constants, and the only interpolated value is the
         * operator's own E2E_PHP, quoted. Node deprecated the args-with-
         * shell form precisely because arguments there are concatenated
         * rather than escaped.
         */
        const php = process.env.E2E_PHP ?? 'php'
        const output = execSync(
            `"${php}" artisan magna:pages:prune-fixtures --quiet-unless-found`,
            { cwd: root, encoding: 'utf8', timeout: 120_000 },
        )
        if (output.trim() !== '') {
            console.log(output.trim())
        }
    } catch (error) {
        // A failed cleanup must not fail a green suite: the run's RESULT is
        // what the suite reports on, and leftovers are a tidiness problem.
        console.warn('Fixture cleanup skipped:', (error as Error).message)
    }
}

export default globalTeardown
