<?php

declare(strict_types=1);

namespace Magna\Pages\Collections;

use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;

/**
 * Site-designed collection templates: pages_template entries of kind
 * `collection`, addressed by slug convention `{type}-single` with the
 * type handle kebab-cased (slug fields refuse underscores: an article
 * type `mount_article` uses slug `mount-article-single`). Designed in the
 * same builder as everything else. Only published templates apply; a
 * missing or drafted template falls back to the built-in views.
 */
class CollectionTemplates
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /** @return array<mixed, mixed>|null */
    public function singleDocument(string $typeHandle): ?array
    {
        if (! $this->schemas->has('pages_template')) {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entry::type('pages_template')
            ->where('slug', str_replace('_', '-', $typeHandle).'-single')
            ->where('kind', 'collection')
            ->where('status', EntryStatus::Published->value)
            ->first();

        $document = $entry?->getAttribute('blocks_data');

        return is_array($document) ? $document : null;
    }
}
