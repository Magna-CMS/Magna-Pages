<?php

declare(strict_types=1);

namespace Magna\Pages\Tests\Feature;

use Illuminate\Support\Facades\Exceptions;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\DocumentHead;
use Magna\Pages\Render\DocumentHeadContext;
use Magna\Pages\Render\DocumentHeadFragment;
use Magna\Pages\Render\PageRenderer;
use Magna\Settings\Events\SettingsSaved;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;
use RuntimeException;

/**
 * The head contract (0.8.0): other plugins add markup to a rendered page's
 * <head> through DocumentHead, and tell the layout which of its own tags to
 * skip. With nobody contributing, a page renders exactly as it did before.
 */
final class DocumentHeadTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/pages';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();

        // Magna SEO's provider is registered whenever SEO's classes exist; it
        // declines while SEO is not enabled, but these tests own the registry.
        $this->registry()->forget('seo');
    }

    private function registry(): DocumentHead
    {
        return $this->app->make(DocumentHead::class);
    }

    private function page(string $slug, string $title = 'Plain Title', array $fields = []): Entry
    {
        $manager = $this->app->make(EntryManager::class);

        $entry = $manager->create('page', array_merge([
            'title' => $title,
            'slug' => $slug,
            'blocks_data' => [[
                'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'On the page']]],
                ]],
            ]],
        ], $fields), $this->author->id);

        return $manager->publish($entry, actorId: $this->author->id);
    }

    private function render(Entry $entry, bool $builderMode = false, bool $withParts = true): string
    {
        // A fresh renderer per render: it memoises per-request lookups.
        $this->app->forgetInstance(PageRenderer::class);

        return $this->app->make(PageRenderer::class)->render($entry, $builderMode, $withParts);
    }

    public function test_with_no_provider_the_head_is_what_it_always_was(): void
    {
        $entry = $this->page('unchanged', fields: ['meta_description' => 'A sentence.']);

        $html = $this->render($entry);

        $this->assertStringContainsString(
            "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>Plain Title</title>\n"
            ."            <meta name=\"description\" content=\"A sentence.\">\n",
            $html,
        );

        // A provider that declines changes nothing either.
        $this->registry()->provide('declines', fn (DocumentHeadContext $context): ?DocumentHeadFragment => null);
        $this->assertSame($html, $this->render($entry));
    }

    public function test_a_providers_markup_is_printed_after_the_viewport_and_replaces_what_it_provides(): void
    {
        $entry = $this->page('provided', fields: ['meta_description' => 'A sentence.']);

        $this->registry()->provide('acme', fn (DocumentHeadContext $context): DocumentHeadFragment => new DocumentHeadFragment(
            '<title>From the provider</title>'."\n".'<meta name="description" content="Provider sentence.">',
            ['title' => true, 'description' => true],
        ));

        $html = $this->render($entry);

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContainsString('<title>From the provider</title>', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertLessThan(
            strpos($html, '<title>From the provider</title>'),
            strpos($html, 'name="viewport"'),
        );
    }

    public function test_a_provider_that_throws_is_reported_and_skipped(): void
    {
        Exceptions::fake();
        $entry = $this->page('throwing');

        $this->registry()->provide('broken', function (DocumentHeadContext $context): DocumentHeadFragment {
            throw new RuntimeException('provider exploded');
        });
        $this->registry()->provide('fine', fn (DocumentHeadContext $context): DocumentHeadFragment => new DocumentHeadFragment('<meta name="fine" content="1">'));

        $html = $this->render($entry);

        $this->assertStringContainsString('<meta name="fine" content="1">', $html);
        $this->assertSame(1, substr_count($html, '<title>'));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_nothing_is_computed_for_the_builder_or_for_chrome(): void
    {
        $calls = 0;
        $this->registry()->provide('counter', function (DocumentHeadContext $context) use (&$calls): DocumentHeadFragment {
            $calls++;

            return new DocumentHeadFragment('<meta name="counted" content="1">');
        });

        $entry = $this->page('editing');

        $this->assertStringNotContainsString('name="counted"', $this->render($entry, builderMode: true));
        $this->assertStringNotContainsString('name="counted"', $this->render($entry, withParts: false));
        $this->app->make(PageRenderer::class)->renderChrome($entry, 'header');
        $this->assertSame(0, $calls);

        $this->assertStringContainsString('name="counted"', $this->render($entry));
        $this->assertSame(1, $calls);
    }

    public function test_providers_are_told_which_page_they_are_describing(): void
    {
        $home = $this->page('home', 'Home', ['meta_title' => 'Acme: the full title']);
        $missing = $this->page('404', 'Not found');
        $other = $this->page('other', 'Other');

        $settings = PagesSettings::get();
        $settings->home_page_id = (string) $home->getKey();
        $settings->not_found_page_id = (string) $missing->getKey();
        $settings->save();

        $seen = [];
        $this->registry()->provide('spy', function (DocumentHeadContext $context) use (&$seen): ?DocumentHeadFragment {
            $seen[$context->title] = $context;

            return null;
        });

        $this->render($home);
        $this->render($missing);
        $this->render($other);

        $this->assertSame(DocumentHeadContext::KIND_PAGE, $seen['Home']->kind);
        $this->assertTrue($seen['Home']->isHome);
        $this->assertFalse($seen['Home']->isNotFound);
        $this->assertSame('Acme: the full title', $seen['Home']->seoTitle);
        $this->assertTrue($seen['Not found']->isNotFound);
        $this->assertFalse($seen['Other']->isHome);
        $this->assertFalse($seen['Other']->isNotFound);
        $this->assertStringStartsWith('http', $seen['Other']->requestUrl);
    }

    public function test_an_seo_settings_save_flushes_the_page_cache_even_from_an_older_seo(): void
    {
        $this->page('cached');

        $this->get('/cached')->assertHeader('X-Magna-Cache', 'miss');
        $this->get('/cached')->assertHeader('X-Magna-Cache', 'hit');

        // What SettingsRepository fires for any write to the "seo" group.
        event(new SettingsSaved('seo'));

        $this->get('/cached')->assertHeader('X-Magna-Cache', 'miss');
    }

    public function test_a_fragment_only_carries_the_keys_of_the_contract(): void
    {
        $fragment = new DocumentHeadFragment('', ['title' => true, 'bogus' => true, 'icon' => 'yes']);

        $this->assertSame(['title' => true, 'description' => false, 'alternates' => false, 'icon' => false, 'canonical' => false], $fragment->provides);
    }
}
