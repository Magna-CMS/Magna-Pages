<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Closure;
use Throwable;

/**
 * The head contract: plugins contribute markup to every rendered page's
 * `<head>` without Pages knowing who they are.
 *
 *     app(DocumentHead::class)->provide('acme', function (DocumentHeadContext $context): ?DocumentHeadFragment {
 *         return new DocumentHeadFragment('<meta name="acme" content="1">');
 *     });
 *
 * The renderer hands the merged result to the theme layout as `$headHtml` and
 * `$headProvides`. A provider returning null contributes nothing; a provider
 * that throws is reported and skipped, because a broken integration must cost
 * a few tags, never the page.
 *
 * Providers run in registration order, once per uncached render. Their output
 * is cached with the page, so anything that changes it must announce itself
 * through core's PublicContentChanged (Magna SEO does).
 */
final class DocumentHead
{
    /** @var array<string, Closure(DocumentHeadContext): ?DocumentHeadFragment> */
    private array $providers = [];

    /**
     * Register a provider under a name. The same name again replaces it, so a
     * plugin booting twice in one process (tests, Octane) cannot print twice.
     *
     * @param  callable(DocumentHeadContext): ?DocumentHeadFragment  $provider
     */
    public function provide(string $name, callable $provider): void
    {
        $this->providers[$name] = Closure::fromCallable($provider);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function forget(string $name): void
    {
        unset($this->providers[$name]);
    }

    /** Whether anyone is listening — the renderer skips building a context otherwise. */
    public function isEmpty(): bool
    {
        return $this->providers === [];
    }

    public function render(DocumentHeadContext $context): DocumentHeadFragment
    {
        $fragment = DocumentHeadFragment::empty();

        foreach ($this->providers as $provider) {
            try {
                $contribution = $provider($context);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($contribution instanceof DocumentHeadFragment) {
                $fragment = $fragment->merge($contribution);
            }
        }

        return $fragment;
    }
}
