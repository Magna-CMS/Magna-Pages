<?php

declare(strict_types=1);

namespace Magna\Pages\Seo;

use Illuminate\Contracts\Foundation\Application;
use Magna\Pages\Render\DocumentHead;
use Magna\Seo\Registry\SeoSourceRegistry;

/**
 * The bridge to the optional Magna SEO plugin. Neither plugin depends on the
 * other: the Magna\Seo names here are compile-time aliases that only autoload
 * behind the class_exists guard, so Pages runs unchanged on a site without SEO,
 * and SEO never learns that Pages exists. It is the same pattern the blog uses.
 *
 * Two things happen when SEO is present: pages become an SEO subject source
 * (sitemap-pages.xml, llms.txt, scans) and the `seo` head provider prints
 * SEO's meta into every rendered page through the DocumentHead contract.
 */
final class PagesSeoIntegration
{
    public static function register(Application $app): void
    {
        if (! class_exists(SeoSourceRegistry::class)) {
            return;
        }

        $source = static function (SeoSourceRegistry $registry) use ($app): void {
            if (! $registry->has('pages')) {
                $registry->register($app->make(PagesSeoSource::class));
            }
        };

        // SEO binds its registry in register(); depending on boot order it may
        // already be resolved by now, in which case afterResolving never fires.
        if ($app->resolved(SeoSourceRegistry::class)) {
            $source($app->make(SeoSourceRegistry::class));
        } else {
            $app->afterResolving(SeoSourceRegistry::class, $source);
        }

        $app->make(DocumentHead::class)->provide('seo', static fn ($context) => $app->make(PagesSeoHead::class)($context));
    }
}
