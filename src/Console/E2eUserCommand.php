<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Magna\Auth\Role;
use Magna\Users\User;

/**
 * Creates (or refreshes) the browser-test account the Playwright suite
 * signs in with. Refuses in production outright — this account's password
 * travels in an env var, which is fine for a dev machine or CI runner and
 * for nothing else.
 */
class E2eUserCommand extends Command
{
    protected $signature = 'magna:pages:e2e-user {--password=e2e-password}';

    protected $description = 'Create the end-to-end test user for the Pages builder Playwright suite.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to create a known-password account in production.');

            return self::FAILURE;
        }

        $role = Role::query()->firstOrCreate(
            ['handle' => 'pages-e2e'],
            ['name' => 'Pages E2E', 'description' => 'Browser-test account for the builder E2E suite.'],
        );
        $role->grant(
            'panel.access',
            'pages.content', 'pages.layout', 'pages.design', 'pages.publish', 'pages.settings',
            'blocks.preview',
            // The builder chooses pictures now, and an account that cannot
            // is not a realistic page editor to test with.
            'media.view', 'media.upload',
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'e2e@magna.test'],
            ['name' => 'E2E Runner', 'password' => Hash::make((string) $this->option('password'))],
        );
        $user->assignRole($role);

        $this->info('E2E user ready: e2e@magna.test');

        return self::SUCCESS;
    }
}
