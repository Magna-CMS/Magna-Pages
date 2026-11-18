<?php

declare(strict_types=1);

namespace Magna\Pages\Tests\Feature;

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Media\Media;
use Magna\Pages\PagesSettings;
use Magna\Pages\Seo\PagesSeoSource;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Settings\SeoSettings;
use Magna\Seo\Support\SeoMetaRepository;
use Magna\Settings\UrlSettings;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

/**
 * Pages with Magna SEO installed: pages reach the sitemap and llms.txt, and
 * every rendered page carries SEO's head through the DocumentHead contract —
 * the page's own title verbatim, an absolute canonical, a share card and one
 * connected schema graph.
 */
final class SeoIntegrationTest extends PluginTestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        if (devPluginManifest('magna-cms/seo') === null) {
            $this->markTestSkipped('Magna SEO is not part of this checkout.');
        }

        $this->enablePlugin('magna-cms/pages');
        $this->enablePlugin('magna-cms/seo');
        $this->app['env'] = 'production';

        $urls = UrlSettings::get();
        $urls->frontend_url = 'https://site.test';
        $urls->save();

        $seo = SeoSettings::get();
        $seo->site_name = 'Acme';
        $seo->organization_name = 'Acme Studios';
        $seo->save();

        $this->author = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $blocks
     */
    private function page(string $slug, string $title, array $fields = [], array $blocks = []): Entry
    {
        $manager = $this->app->make(EntryManager::class);

        $entry = $manager->create('page', array_merge([
            'title' => $title,
            'slug' => $slug,
            'blocks_data' => [[
                'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                    'blocks' => $blocks !== [] ? $blocks : [[
                        'id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $title.' heading'],
                    ]],
                ]],
            ]],
        ], $fields), $this->author->id);

        return $manager->publish($entry, actorId: $this->author->id);
    }

    private function site(): array
    {
        $home = $this->page('home', 'Home', [
            'meta_title' => 'Acme billing software: offline GST billing',
            'meta_description' => 'Acme is a billing app for shops.',
        ]);
        $faq = $this->page('faq', 'FAQ', [], [[
            'id' => 'blk-faq', 'block' => 'faq', 'settings' => [],
            'data' => ['items' => [
                ['question' => 'Is Acme free?', 'answer' => 'Yes, the <strong>free</strong> plan never expires.'],
                ['question' => 'Does it work offline?', 'answer' => 'Yes.'],
            ]],
        ]]);
        $missing = $this->page('404', 'Page not found');

        $settings = PagesSettings::get();
        $settings->home_page_id = (string) $home->getKey();
        $settings->not_found_page_id = (string) $missing->getKey();
        $settings->save();

        return [$home, $faq, $missing];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graph(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $match);
        $decoded = json_decode($match[1] ?? 'null', true);

        return is_array($decoded) ? ($decoded['@graph'] ?? []) : [];
    }

    public function test_the_home_page_carries_its_own_title_verbatim_and_an_absolute_root_canonical(): void
    {
        $this->site();

        $html = (string) $this->get('/home')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContainsString('<title>Acme billing software: offline GST billing</title>', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertStringContainsString('<link rel="canonical" href="https://site.test/">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://site.test/">', $html);

        $types = array_column($this->graph($html), '@type');
        $this->assertContains('WebSite', $types);
        $this->assertContains('WebPage', $types);
        $this->assertContains('Organization', $types);
    }

    public function test_the_home_page_at_its_slug_points_its_canonical_at_the_root(): void
    {
        $this->site();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://site.test/">',
            (string) $this->get('/home')->assertOk()->getContent(),
        );
    }

    public function test_an_explicit_seo_override_beats_the_pages_own_fields(): void
    {
        [$home] = $this->site();
        $this->app->make(SeoMetaRepository::class)->upsert($home->getMorphClass(), (string) $home->getKey(), ['title' => 'Override title']);

        $this->assertStringContainsString('<title>Override title</title>', (string) $this->get('/home')->getContent());
    }

    public function test_the_subject_carries_the_search_title_the_page_prints(): void
    {
        [$home, $faq] = $this->site();
        $source = $this->app->make(PagesSeoSource::class);

        // What SEO's analysis scores; without it only the short name "Home" is seen.
        $this->assertSame('Acme billing software: offline GST billing', $source->resolve((string) $home->getKey())?->raw['meta_title'] ?? null);
        $this->assertArrayNotHasKey('meta_title', $source->resolve((string) $faq->getKey())->raw ?? []);
    }

    public function test_the_default_share_image_carries_its_dimensions(): void
    {
        $this->site();
        $image = Media::create([
            'disk' => 'public', 'path' => 'og.png', 'filename' => 'og.png', 'original_filename' => 'og.png',
            'mime_type' => 'image/png', 'size' => 120000, 'width' => 1200, 'height' => 630,
        ]);
        $settings = SeoSettings::get();
        $settings->default_og_image = $image->id;
        $settings->save();

        $html = (string) $this->get('/home')->getContent();

        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+og\.png">#', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function test_faq_blocks_become_faq_structured_data(): void
    {
        $this->site();

        $faq = collect($this->graph((string) $this->get('/faq')->getContent()))->firstWhere('@type', 'FAQPage');

        $this->assertNotNull($faq);
        $this->assertSame('Is Acme free?', $faq['mainEntity'][0]['name']);
        $this->assertSame('Yes, the free plan never expires.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function test_the_not_found_page_is_noindex_and_stays_out_of_the_sitemap(): void
    {
        $this->site();

        $this->assertStringContainsString('content="noindex, follow"', (string) $this->get('/no-such-page')->assertNotFound()->getContent());
        $this->assertStringContainsString('content="noindex, follow"', (string) $this->get('/404')->getContent());

        $sitemap = (string) $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/404', $sitemap);
    }

    public function test_the_sitemap_lists_pages_at_the_addresses_they_are_served_at(): void
    {
        $this->site();
        $hidden = $this->page('hidden', 'Hidden');
        $meta = new SeoMeta(['robots_index' => false]);
        $meta->setAttribute('seoable_type', $hidden->getMorphClass());
        $meta->setAttribute('seoable_id', (string) $hidden->getKey());
        $meta->save();

        $this->assertStringContainsString('sitemap-pages.xml', (string) $this->get('/sitemap.xml')->assertOk()->getContent());

        $sitemap = (string) $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://site.test/</loc>', $sitemap);
        $this->assertStringContainsString('<loc>https://site.test/faq</loc>', $sitemap);
        $this->assertStringNotContainsString('https://site.test/home', $sitemap);
        $this->assertStringNotContainsString('/hidden', $sitemap);
    }

    public function test_pages_are_listed_in_llms_txt(): void
    {
        $this->site();

        $llms = (string) $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('https://site.test/faq', $llms);
        $this->assertStringNotContainsString('https://site.test/404', $llms);
    }

    public function test_a_software_application_from_the_override_reaches_the_home_page(): void
    {
        [$home] = $this->site();
        $this->app->make(SeoMetaRepository::class)->upsert($home->getMorphClass(), (string) $home->getKey(), [
            'schema_overrides' => ['software_application' => [
                'name' => 'Acme',
                'application_category' => 'FinanceApplication',
                'operating_systems' => ['Android', 'Windows'],
                'offers' => [['name' => 'Free', 'price' => '0', 'currency' => 'INR']],
            ]],
        ]);

        $app = collect($this->graph((string) $this->get('/home')->getContent()))->firstWhere('@type', 'SoftwareApplication');

        $this->assertNotNull($app);
        $this->assertSame('https://site.test/#software-acme', $app['@id']);
        $this->assertArrayNotHasKey('aggregateRating', $app);
        // The page's own title still comes from its field, not the override.
        $this->assertStringContainsString('<title>Acme billing software: offline GST billing</title>', (string) $this->get('/home')->getContent());
    }

    public function test_saving_seo_settings_drops_cached_pages(): void
    {
        $this->site();

        $this->get('/faq')->assertHeader('X-Magna-Cache', 'miss');
        $this->get('/faq')->assertHeader('X-Magna-Cache', 'hit');

        $settings = SeoSettings::get();
        $settings->google_site_verification = 'token-123';
        $settings->save();

        $response = $this->get('/faq');
        $response->assertHeader('X-Magna-Cache', 'miss');
        $this->assertStringContainsString('<meta name="google-site-verification" content="token-123">', (string) $response->getContent());
    }
}
