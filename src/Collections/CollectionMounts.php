<?php

declare(strict_types=1);

namespace Magna\Pages\Collections;

use Magna\Content\ContentType;
use Magna\Content\SchemaRegistry;
use Magna\Pages\PagesSettings;

/**
 * The validated view of PagesSettings::collection_mounts — which content
 * types are mounted where on the public site (05-SITE-STRUCTURE §1).
 *
 * Validation happens on READ, every time: a mount whose type vanished,
 * lost its publiclyRenderable flag (§C4), or carries a malformed prefix is
 * silently dropped, so a stale settings row can never expose a type the
 * schema no longer offers to the public.
 */
class CollectionMounts
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * @return list<array{type: string, prefix: string}>
     */
    public function all(): array
    {
        $mounts = [];
        foreach (PagesSettings::get()->collection_mounts as $mount) {
            if (! is_array($mount)) {
                continue;
            }
            $type = $mount['type'] ?? null;
            $prefix = $mount['prefix'] ?? null;
            if (! is_string($type) || ! is_string($prefix)
                || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $prefix) !== 1
            ) {
                continue;
            }

            $contentType = $this->schemas->get($type);
            if ($contentType === null || ! $contentType->publiclyRenderable) {
                continue;
            }

            $mounts[] = ['type' => $type, 'prefix' => $prefix];
        }

        return $mounts;
    }

    /**
     * What the given site path addresses, if any mount claims it:
     * the prefix alone = the archive, prefix/slug = a single entry.
     *
     * @return array{mode: string, type: string, prefix: string, slug: string|null}|null
     */
    public function match(string $path): ?array
    {
        $path = trim($path, '/');
        foreach ($this->all() as $mount) {
            if ($path === $mount['prefix']) {
                return [...$mount, 'mode' => 'archive', 'slug' => null];
            }
            if (str_starts_with($path, $mount['prefix'].'/')) {
                $slug = substr($path, strlen($mount['prefix']) + 1);
                if ($slug !== '' && ! str_contains($slug, '/')) {
                    return [...$mount, 'mode' => 'single', 'slug' => $slug];
                }
            }
        }

        return null;
    }

    public function contentType(string $handle): ?ContentType
    {
        return $this->schemas->get($handle);
    }
}
