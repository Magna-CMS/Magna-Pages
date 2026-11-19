<?php

declare(strict_types=1);

namespace Magna\Pages\Tests\Feature;

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\PageRenderer;
use Magna\Pages\SiteKit\PageSlugs;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

/**
 * A site kit that carries translations: English slugs in every locale
 * (`features` in `ml` is the Malayalam features page), linked into one
 * translation group by the kit apply, with chrome that follows the page's
 * locale and a language switcher that works in the footer.
 */
final class LocalizedSiteKitTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/pages';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = LocalizationSettings::get();
        $settings->available_locales = ['en', 'ml'];
        $settings->fallback_locale = 'en';
        $this->app->make(SettingsRepository::class)->persist($settings);

        $this->author = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $slug, string $locale, string $text, array $blocks = []): array
    {
        return [
            'status' => 'published', 'title' => $text, 'slug' => $slug, 'path' => $slug, 'locale' => $locale,
            'blocks_data' => ['schemaVersion' => '1.0', 'sections' => [[
                'id' => "sec-{$locale}-{$slug}", 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => "col-{$locale}-{$slug}", 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => "h-{$locale}-{$slug}", 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]], ...$blocks],
                ]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function footer(string $locale, string $text): array
    {
        return [
            'status' => 'published', 'title' => "Footer {$locale}", 'slug' => 'footer', 'locale' => $locale,
            'kind' => 'part', 'role' => 'footer',
            'blocks_data' => ['schemaVersion' => '1.0', 'sections' => [[
                'id' => "fsec-{$locale}", 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => "fcol-{$locale}", 'span' => 12, 'settings' => [],
                    'blocks' => [
                        ['id' => "ft-{$locale}", 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]],
                        ['id' => "fsw-{$locale}", 'block' => 'locale-switcher', 'settings' => [], 'data' => []],
                    ],
                ]],
            ]]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $templates
     */
    private function applyKit(array $pages, array $templates = []): void
    {
        $this->app->make(SiteKit::class)->apply([
            'format' => 'magna-site-kit', 'version' => 1,
            'pages' => $pages, 'templates' => $templates, 'menus' => [],
            'settings' => ['home_page_slug' => 'home', 'not_found_page_slug' => '404'],
        ], (string) $this->author->id);
    }

    private function entry(string $type, string $slug, string $locale): Entry
    {
        $query = Entry::type($type)->where('slug', $slug);
        $locale === ''
            ? $query->where(fn ($q) => $q->where('locale', '')->orWhere('locale', 'en')->orWhereNull('locale'))
            : $query->where('locale', $locale);

        $entry = $query->first();
        $this->assertInstanceOf(Entry::class, $entry, "{$type} {$slug} [{$locale}]");

        return $entry;
    }

    public function test_a_slug_names_the_fallback_locale_page_even_when_a_translation_is_older(): void
    {
        $manager = $this->app->make(EntryManager::class);
        // The Malayalam row is created FIRST, so an unscoped first() finds it.
        $manager->create('page', ['title' => 'ഹോം', 'slug' => 'home', 'locale' => 'ml', 'blocks_data' => []], (string) $this->author->id);
        $en = $manager->create('page', ['title' => 'Home', 'slug' => 'home', 'locale' => 'en', 'blocks_data' => []], (string) $this->author->id);

        $this->assertSame($en->getKey(), $this->app->make(PageSlugs::class)->idForSlug('home'));
    }

    public function test_applying_a_kit_links_each_translation_to_its_english_twin(): void
    {
        $this->applyKit([
            $this->page('home', '', 'Home'),
            $this->page('features', '', 'Features'),
            $this->page('home', 'ml', 'ഹോം'),
            $this->page('features', 'ml', 'സവിശേഷതകൾ'),
            $this->page('404', '', 'Not found'),
        ]);

        foreach (['home', 'features'] as $slug) {
            $en = $this->entry('page', $slug, '');
            $ml = $this->entry('page', $slug, 'ml');
            $this->assertNotSame($en->getKey(), $ml->getKey());
            $this->assertSame($en->translation_group, $ml->translation_group, $slug);
        }

        // The home page stays the English row: Malayalam lives at /ml.
        $this->assertSame($this->entry('page', 'home', '')->getKey(), PagesSettings::get()->home_page_id);
    }

    public function test_reapplying_the_kit_keeps_the_linkage(): void
    {
        $kit = [$this->page('features', '', 'Features'), $this->page('features', 'ml', 'സവിശേഷതകൾ')];
        $this->applyKit($kit);
        $group = $this->entry('page', 'features', 'ml')->translation_group;

        $this->applyKit($kit);

        $this->assertSame($group, $this->entry('page', 'features', 'ml')->translation_group);
        $this->assertSame($group, $this->entry('page', 'features', '')->translation_group);
    }

    public function test_chrome_follows_the_pages_locale_and_falls_back_to_english(): void
    {
        $this->applyKit(
            [$this->page('features', '', 'Features'), $this->page('features', 'ml', 'സവിശേഷതകൾ'), $this->page('about', 'ml', 'ഞങ്ങളെക്കുറിച്ച്')],
            [$this->footer('', 'English footer'), $this->footer('ml', 'മലയാളം footer')],
        );
        $parts = $this->app->make(TemplatePartResolver::class);

        $this->assertSame('', (string) $parts->chromeEntry('footer', $this->entry('page', 'features', ''))?->locale);
        $this->assertSame('ml', $parts->chromeEntry('footer', $this->entry('page', 'features', 'ml'))?->locale);
        $this->assertSame('ml', $parts->chromeEntry('footer', $this->entry('page', 'about', 'ml'))?->locale);
    }

    public function test_an_untranslated_footer_still_renders_on_a_translated_page(): void
    {
        $this->applyKit(
            [$this->page('features', '', 'Features'), $this->page('features', 'ml', 'സവിശേഷതകൾ')],
            [$this->footer('', 'English footer')],
        );

        $footer = $this->app->make(TemplatePartResolver::class)->chromeEntry('footer', $this->entry('page', 'features', 'ml'));

        $this->assertNotNull($footer);
        $this->assertContains((string) $footer->getAttribute('locale'), ['', 'en']);
    }

    public function test_a_language_switcher_in_the_footer_links_the_pages_translations(): void
    {
        $this->applyKit(
            [$this->page('features', '', 'Features'), $this->page('features', 'ml', 'സവിശേഷതകൾ')],
            [$this->footer('', 'English footer'), $this->footer('ml', 'മലയാളം footer')],
        );

        // What PageController does for a request under /ml.
        $this->app->setLocale('ml');
        $html = $this->app->make(PageRenderer::class)->render($this->entry('page', 'features', 'ml'));
        $footer = substr($html, (int) strpos($html, 'magna-chrome--footer'));

        $this->assertStringContainsString('മലയാളം footer', $footer);
        $this->assertStringContainsString('magna-locale-switcher', $footer);
        // The way back to English is a link, from inside the footer.
        $this->assertMatchesRegularExpression('#<a href="[^"]*/features"[^>]*hreflang="en"#', $footer);
    }
}
