<?php

declare(strict_types=1);

namespace Magna\Pages\Console;

use Illuminate\Console\Command;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\SchemaRegistry;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Settings\SettingsRepository;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeSettings;
use Throwable;

/**
 * Seeds a small, fully wired demo site: three published pages, the primary
 * menu, the home-page setting, and the Launch theme — the "60-second test"
 * in one command. Content is plainly fictional and deletable page by page.
 */
class InstallDemoCommand extends Command
{
    protected $signature = 'magna:pages:demo {--force : Seed even when pages already exist}';

    protected $description = 'Install the Magna Pages demo site (pages, menu, home page, Launch theme).';

    public function handle(
        SchemaRegistry $schemaRegistry,
        EntryManager $entries,
        MenuManager $menus,
        ThemeManager $themes,
    ): int {
        if (! $schemaRegistry->has('page')) {
            $this->error('The page content type is missing — enable the magna/pages plugin first.');

            return self::FAILURE;
        }

        if (! $this->option('force') && Entry::type('page')->count() > 0) {
            $this->warn('Pages already exist — refusing to seed demo content. Use --force to seed anyway.');

            return self::FAILURE;
        }

        $home = $this->publish($entries, 'Welcome to Northwind Coffee', 'welcome', [
            $this->section('demo-hero', [
                $this->block('demo-hero-block', 'hero', [
                    'layout' => 'centered',
                    'headline' => 'Slow mornings, honest coffee',
                    'subheadline' => 'Small-batch roasting in the heart of the city since 2019.',
                    'cta_primary_label' => 'Our story',
                    'cta_primary_url' => '/about',
                    'cta_secondary_label' => 'Questions?',
                    'cta_secondary_url' => '/faq',
                ]),
            ]),
            $this->section('demo-features', [
                $this->block('demo-features-block', 'features', [
                    'items' => [
                        ['icon' => '☕', 'title' => 'Roasted weekly', 'description' => 'Beans never older than seven days when they reach your cup.'],
                        ['icon' => '🌱', 'title' => 'Direct trade', 'description' => 'We pay farmers first and middlemen never.'],
                        ['icon' => '🚲', 'title' => 'City delivery', 'description' => 'Carbon-neutral courier delivery inside the ring road.'],
                    ],
                    'layout' => 'icon-grid',
                ]),
            ]),
            $this->section('demo-cta', [
                $this->block('demo-cta-block', 'cta', [
                    'headline' => 'Taste the difference',
                    'body' => 'Visit the roastery or order a sampler box — your first espresso is on us.',
                    'button_primary_label' => 'Read our FAQ',
                    'button_primary_url' => '/faq',
                ]),
            ]),
        ]);

        $about = $this->publish($entries, 'About us', 'about', [
            $this->section('demo-about', [
                $this->block('demo-about-heading', 'heading', ['text' => 'Three friends and one roaster', 'level' => 'h2', 'align' => 'left']),
                $this->block('demo-about-text', 'text', [
                    'body' => '<p>Northwind started in a garage with a secondhand drum roaster and a stubborn belief: coffee tastes better when everyone in the chain is paid fairly.</p><p>Today we roast for forty cafés — and still cup every batch by hand.</p>',
                ]),
            ]),
        ]);

        $faq = $this->publish($entries, 'Frequently asked questions', 'faq', [
            $this->section('demo-faq', [
                $this->block('demo-faq-heading', 'heading', ['text' => 'Good questions', 'level' => 'h2', 'align' => 'left']),
                $this->block('demo-faq-block', 'faq', [
                    'items' => [
                        ['question' => 'Do you ship whole bean?', 'answer' => 'Always. Ground on request, but we will quietly judge you.'],
                        ['question' => 'Is the packaging recyclable?', 'answer' => 'Fully — the bags compost in about twelve weeks.'],
                        ['question' => 'Can I visit the roastery?', 'answer' => 'Saturdays 9–14, no booking needed.'],
                    ],
                ]),
            ]),
        ]);

        $menu = Menu::query()->firstWhere('handle', 'primary')
            ?? $menus->create('primary', 'Primary navigation');
        $menus->syncItems($menu, [
            ['label' => 'Home', 'type' => 'page', 'page_id' => (string) $home->getKey()],
            ['label' => 'About', 'type' => 'page', 'page_id' => (string) $about->getKey()],
            ['label' => 'FAQ', 'type' => 'page', 'page_id' => (string) $faq->getKey()],
        ]);

        $settings = PagesSettings::get();
        $settings->home_page_id = (string) $home->getKey();
        app(SettingsRepository::class)->persist($settings);

        // Activate Launch when installed and nothing else is active — a demo
        // must look finished, but never silently replace a chosen theme.
        if (ThemeSettings::get()->active === null) {
            try {
                $themes->activate('magna/launch');
                $this->info('Activated the Launch theme.');
            } catch (Throwable) {
                $this->warn('Launch theme not installed — pages will use the built-in shell.');
            }
        }

        $this->info('Demo site installed: /welcome, /about, /faq (primary menu + home page set).');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function publish(EntryManager $entries, string $title, string $slug, array $sections): Entry
    {
        $entry = $entries->create('page', [
            'title' => $title,
            'slug' => $slug,
            'blocks_data' => $sections,
        ]);

        return $entries->publish($entry);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function section(string $id, array $blocks): array
    {
        return [
            'id' => $id, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => $id.'-col', 'span' => 12, 'settings' => [], 'blocks' => $blocks,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function block(string $id, string $handle, array $data): array
    {
        return ['id' => $id, 'block' => $handle, 'settings' => [], 'data' => $data];
    }
}
