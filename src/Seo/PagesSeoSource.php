<?php

declare(strict_types=1);

namespace Magna\Pages\Seo;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Magna\Blocks\PageTree;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Frontend\LocaleUrls;
use Magna\Pages\PagesSettings;
use Magna\Pages\Render\LocaleAlternates;
use Magna\Seo\Contracts\SeoSubjectSource;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Settings\UrlSettings;

/**
 * Published pages as Magna SEO subjects — what puts pages into /sitemap.xml,
 * /llms.txt and the SEO scans, and what the head provider describes.
 *
 * Only loaded when Magna SEO is installed (see PagesSeoIntegration), so the
 * Magna\Seo types here never autoload on a site without it.
 *
 * URLs are the ones the router serves, made absolute against the site's
 * frontend URL. The home page is the site root, never /home — the same page
 * at two addresses is one too many for a sitemap. The not-found page is a
 * published page too, but it is never a destination: it stays out of the
 * sitemap and is noindex wherever it renders. In maintenance mode nothing is
 * listed, since every URL answers with the holding page.
 */
final class PagesSeoSource implements SeoSubjectSource
{
    /** Block data keys that never hold readable text. */
    private const NON_TEXT_KEYS = ['url', 'href', 'src', 'image', 'icon', 'id', 'link', 'style', 'class', 'menu', 'source', 'variant', 'experiment'];

    /** @var array<string, string|null> settings page id => translation_group */
    private array $groups = [];

    public function __construct(
        private readonly LocaleUrls $localeUrls,
        private readonly LocaleAlternates $alternates,
    ) {}

    public function handle(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'Pages';
    }

    public function resolve(string $id): ?SeoSubject
    {
        $entry = Entry::type('page')
            ->whereKey($id)
            ->where('status', EntryStatus::Published->value)
            ->first();

        return $entry instanceof Entry ? $this->subjectFor($entry) : null;
    }

    public function chunk(callable $callback, int $size = 500): void
    {
        $settings = PagesSettings::get();

        if ($settings->maintenance_mode) {
            return;
        }

        $notFound = $settings->not_found_page_id;

        Entry::type('page')
            ->where('status', EntryStatus::Published->value)
            ->when(is_string($notFound) && $notFound !== '', fn ($query) => $query->whereKeyNot($notFound))
            ->orderBy('id')
            ->chunk($size, function (Collection $entries) use ($callback, $settings): void {
                $subjects = [];
                foreach ($entries as $entry) {
                    if ($entry instanceof Entry && ! $this->isNotFound($entry, $settings)) {
                        $subjects[] = $this->subjectFor($entry, $settings);
                    }
                }

                if ($subjects !== []) {
                    $callback($subjects);
                }
            });
    }

    public function modelClass(): string
    {
        return Entry::class;
    }

    /**
     * The subject for one page. Public so the head provider describes a page
     * exactly the way the sitemap lists it.
     */
    public function subjectFor(Entry $entry, ?PagesSettings $settings = null): SeoSubject
    {
        $settings ??= PagesSettings::get();
        $isHome = $this->isHome($entry, $settings);
        $locale = $this->locale($entry);
        $title = $entry->getAttribute('title');
        $description = $entry->getAttribute('meta_description');
        $document = $entry->getAttribute('blocks_data');
        $tree = PageTree::fromArray(is_array($document) ? $document : []);

        $raw = [];
        $faq = $this->faq($tree);
        if ($faq !== []) {
            $raw['faq'] = $faq;
        }

        // The page's own search title, which the head prints verbatim (see
        // PagesSeoHead). SEO scores the title that is published, and without
        // this it could only see the page's short name.
        $metaTitle = $entry->getAttribute('meta_title');
        if (is_string($metaTitle) && trim($metaTitle) !== '') {
            $raw['meta_title'] = trim($metaTitle);
        }

        $alternates = [];
        foreach ($this->alternates->forEntry($entry) as $alternateLocale => $path) {
            $alternates[$alternateLocale] = self::absolute($path);
        }

        $key = (string) $entry->getKey();

        return new SeoSubject(
            key: 'pages:'.$key,
            type: $isHome ? SubjectType::Home : SubjectType::Page,
            url: $this->url($entry, $locale, $isHome),
            title: is_string($title) ? $title : '',
            locale: $locale,
            indexable: ! $this->isNotFound($entry, $settings),
            updatedAt: DateTimeImmutable::createFromInterface($entry->updated_at ?? now()),
            plainText: $this->plainText($tree),
            excerpt: is_string($description) && trim($description) !== '' ? trim($description) : null,
            publishedAt: $entry->published_at !== null ? DateTimeImmutable::createFromInterface($entry->published_at) : null,
            alternates: count($alternates) > 1 ? $alternates : [],
            raw: $raw,
            modelId: $key,
        );
    }

    /**
     * A site-relative path made absolute against the frontend URL in core's URL
     * settings — the address visitors and crawlers use — or against this
     * request's host when none is configured.
     */
    public static function absolute(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $base = rtrim((string) UrlSettings::get()->frontend_url, '/');

        return $base !== '' ? $base.$path : url($path);
    }

    public function isNotFound(Entry $entry, PagesSettings $settings): bool
    {
        return $this->matches($entry, $settings->not_found_page_id);
    }

    private function isHome(Entry $entry, PagesSettings $settings): bool
    {
        return $this->matches($entry, $settings->home_page_id);
    }

    /** The setting's page itself, or one of its translations. */
    private function matches(Entry $entry, ?string $id): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        if ($entry->getKey() === $id) {
            return true;
        }

        $group = $entry->translation_group;
        if (! is_string($group) || $group === '') {
            return false;
        }

        // Keyed by id, so a changed setting is a fresh lookup; one query per
        // setting rather than one per page in a sitemap build.
        if (! array_key_exists($id, $this->groups)) {
            $value = Entry::type('page')->whereKey($id)->value('translation_group');
            $this->groups[$id] = is_string($value) ? $value : null;
        }

        return $this->groups[$id] === $group;
    }

    private function url(Entry $entry, string $locale, bool $isHome): string
    {
        if ($isHome) {
            return self::absolute($this->localeUrls->urlFor($locale, ''));
        }

        $path = $entry->getAttribute('path');
        $slug = $entry->getAttribute('slug');
        $segment = is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');

        return self::absolute($this->localeUrls->urlFor($locale, $segment));
    }

    private function locale(Entry $entry): string
    {
        $locale = $entry->getAttribute('locale');

        // '' is how EntryManager stores "the fallback locale".
        return is_string($locale) && $locale !== '' ? $locale : $this->localeUrls->fallback();
    }

    /**
     * Question/answer pairs from every FAQ block on the page, in order — what
     * Magna SEO turns into FAQPage structured data.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faq(PageTree $tree): array
    {
        $pairs = [];

        $this->walk($tree->toArray(), function (array $node) use (&$pairs): void {
            if (($node['block'] ?? null) !== 'faq') {
                return;
            }

            $items = $node['data']['items'] ?? null;
            foreach (is_array($items) ? $items : [] as $item) {
                $question = is_array($item) && is_string($item['question'] ?? null) ? $this->text($item['question']) : '';
                $answer = is_array($item) && is_string($item['answer'] ?? null) ? $this->text($item['answer']) : '';

                if ($question !== '' && $answer !== '') {
                    $pairs[] = ['question' => $question, 'answer' => $answer];
                }
            }
        });

        return $pairs;
    }

    /**
     * The page's readable text, for llms-full.txt and analysis: every string a
     * block holds as content, tags stripped. Settings, URLs and ids are skipped.
     */
    private function plainText(PageTree $tree): string
    {
        $parts = [];

        $this->walk($tree->toArray(), function (array $node) use (&$parts): void {
            if (! isset($node['block']) || ! is_array($node['data'] ?? null)) {
                return;
            }

            array_walk_recursive($node['data'], function (mixed $value, int|string $key) use (&$parts): void {
                if (is_string($value) && ! in_array($key, self::NON_TEXT_KEYS, true)) {
                    $text = $this->text($value);
                    if ($text !== '' && preg_match('#^(https?:)?//|^/[^\s]*$|^\#[0-9a-f]{3,8}$#i', $text) !== 1) {
                        $parts[] = $text;
                    }
                }
            });
        });

        return implode("\n", $parts);
    }

    /**
     * @param  array<mixed>  $node
     * @param  callable(array<mixed>): void  $visit
     */
    private function walk(array $node, callable $visit): void
    {
        $visit($node);

        foreach ($node as $key => $child) {
            if (is_array($child) && $key !== 'data') {
                $this->walk($child, $visit);
            }
        }
    }

    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
