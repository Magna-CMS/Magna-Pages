<?php

declare(strict_types=1);

namespace Magna\Pages\Seo;

use DateTimeImmutable;
use Magna\Content\Entry;
use Magna\Pages\Render\DocumentHeadContext;
use Magna\Pages\Render\DocumentHeadFragment;
use Magna\Seo\Enums\SubjectType;
use Magna\Seo\Head\HeadHtmlRenderer;
use Magna\Seo\Head\SeoHeadRenderer;
use Magna\Seo\Models\SeoMeta;
use Magna\Seo\Registry\SeoSourceRegistry;
use Magna\Seo\Subjects\SeoSubject;
use Magna\Seo\Support\SeoMetaRepository;

/**
 * The `seo` head provider: Magna SEO's meta, canonical, share cards and
 * structured data printed into every rendered page.
 *
 * A page's own SEO title and description (the meta_title / meta_description
 * a site kit carries) reach SEO as its override, so a title written in full
 * by the author is printed verbatim rather than run through a template. An
 * explicit per-page SEO override still wins over both.
 *
 * Anything else drawn in the site shell (a blog, a collection) gets a minimal
 * subject for now — canonical, share card, site image, verification — so the
 * whole site carries the same site-wide signals.
 */
final class PagesSeoHead
{
    public function __construct(private readonly PagesSeoSource $source) {}

    public function __invoke(DocumentHeadContext $context): ?DocumentHeadFragment
    {
        // Installed but disabled: the classes autoload, the plugin never booted.
        if (! app()->bound(SeoSourceRegistry::class)) {
            return null;
        }

        $entry = $context->entry;

        [$subject, $override] = $context->kind === DocumentHeadContext::KIND_PAGE && $entry instanceof Entry
            ? [$this->source->subjectFor($entry), $this->pageOverride($entry, $context)]
            : [$this->frontendSubject($context), $this->frontendOverride($context)];

        $head = app(SeoHeadRenderer::class)->head($subject, $override);

        return new DocumentHeadFragment(app(HeadHtmlRenderer::class)->render($head), [
            'title' => true,
            'description' => isset($head->metaNames['description']),
            'alternates' => $head->alternates !== [],
            'icon' => isset($head->links['icon']),
            'canonical' => isset($head->links['canonical']),
        ]);
    }

    /**
     * The stored override if there is one, else an unsaved one — never written
     * back. The page's own fields fill whatever the override leaves blank.
     */
    private function pageOverride(Entry $entry, DocumentHeadContext $context): SeoMeta
    {
        $stored = app(SeoMetaRepository::class)->forModel($entry);
        $override = $stored !== null ? clone $stored : new SeoMeta;

        if (trim((string) $override->title) === '' && $context->seoTitle !== '') {
            $override->title = $context->seoTitle;
        }

        if (trim((string) $override->description) === '' && $context->metaDescription !== '') {
            $override->description = $context->metaDescription;
        }

        // The not-found page answers a URL that does not exist. Whatever an
        // editor set, it must never be indexed as content.
        if ($context->isNotFound) {
            $override->robots_index = false;
        }

        return $override;
    }

    private function frontendSubject(DocumentHeadContext $context): SeoSubject
    {
        $path = (string) parse_url($context->requestUrl, PHP_URL_PATH);

        return new SeoSubject(
            key: 'frontend:'.$path,
            type: SubjectType::Page,
            url: PagesSeoSource::absolute($path),
            title: $context->title,
            locale: str_replace('_', '-', app()->getLocale()),
            indexable: ! $context->isNotFound,
            updatedAt: new DateTimeImmutable,
            excerpt: $context->metaDescription !== '' ? $context->metaDescription : null,
        );
    }

    private function frontendOverride(DocumentHeadContext $context): ?SeoMeta
    {
        if ($context->seoTitle === '' && $context->metaDescription === '') {
            return null;
        }

        $override = new SeoMeta;
        $override->title = $context->seoTitle !== '' ? $context->seoTitle : null;
        $override->description = $context->metaDescription !== '' ? $context->metaDescription : null;

        return $override;
    }
}
