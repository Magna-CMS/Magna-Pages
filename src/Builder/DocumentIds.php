<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Support\Str;

/**
 * Fresh structural ids for a document subtree — shared by pattern
 * instantiation and library insertion, because "the same asset inserted
 * twice must be two families of ids" is one rule, not two implementations.
 */
final class DocumentIds
{
    /**
     * Regenerate `id` on this node and every structural child. Only the
     * structural containers are walked — an "id" key inside block DATA is
     * content and survives untouched.
     *
     * @param  array<mixed, mixed>  $node
     * @return array<mixed, mixed>
     */
    public static function fresh(array $node): array
    {
        if (array_key_exists('id', $node)) {
            $node['id'] = strtolower((string) Str::ulid());
        }

        foreach (['sections', 'columns', 'blocks', 'children'] as $container) {
            if (isset($node[$container]) && is_array($node[$container])) {
                $node[$container] = array_map(
                    fn (mixed $child): mixed => is_array($child) ? self::fresh($child) : $child,
                    $node[$container],
                );
            }
        }

        return $node;
    }
}
