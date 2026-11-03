<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\PageTree;
use Magna\Content\Entry;

/**
 * Everything a head provider may want to know about the document being
 * rendered, resolved once by the renderer — providers receive data; they do not
 * go looking for it.
 */
final readonly class DocumentHeadContext
{
    /** A stored page entry. */
    public const KIND_PAGE = 'page';

    /** Anything else drawn in the site shell: a plugin frontend page, a collection archive or single. */
    public const KIND_FRONTEND = 'frontend';

    /**
     * @param  Entry|null  $entry  the page (KIND_PAGE) or other entry being shown, when there is one
     * @param  string  $title  the document's own title ("Pricing")
     * @param  string  $seoTitle  a page's full search title, verbatim, or ''
     * @param  string  $metaDescription  the page's description, or ''
     * @param  array<string, string>  $localeAlternates  locale => site-relative URL
     * @param  string  $requestUrl  absolute URL of this request, without the query string
     */
    public function __construct(
        public string $kind,
        public ?Entry $entry,
        public string $title,
        public string $seoTitle,
        public string $metaDescription,
        public array $localeAlternates,
        public bool $isHome,
        public bool $isNotFound,
        public PageTree $tree,
        public string $requestUrl,
    ) {}
}
