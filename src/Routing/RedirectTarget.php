<?php

declare(strict_types=1);

namespace Magna\Pages\Routing;

/** Where a matched redirect sends the visitor: a site-relative URL, locale prefix included. */
final class RedirectTarget
{
    public function __construct(
        public readonly int $status,
        public readonly string $path,
    ) {}
}
