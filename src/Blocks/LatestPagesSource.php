<?php

declare(strict_types=1);

namespace Magna\Pages\Blocks;

use Magna\Blocks\DataSources\DataSource;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;

/**
 * The built-in entries source: latest published pages, newest first — the
 * Loop block's "recent pages" case with zero plugins installed. Other
 * content types do NOT get an automatic source: whether a type's entries
 * are public is a judgement only its owning plugin can make, so each
 * plugin registers its own sources via RegistersDataSources.
 */
final class LatestPagesSource implements DataSource
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    public function handle(): string
    {
        return 'pages.latest';
    }

    public function label(): string
    {
        return 'Latest pages';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, string>>
     */
    public function fetch(array $config): array
    {
        if (! $this->schemas->has('page')) {
            return [];
        }

        $limit = is_numeric($config['limit'] ?? null) ? max(1, min(50, (int) $config['limit'])) : 6;

        $items = [];
        foreach (Entry::type('page')
            ->where('status', EntryStatus::Published->value)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get() as $entry) {
            $title = $entry->getAttribute('title');
            $path = $entry->getAttribute('path');
            $slug = $entry->getAttribute('slug');
            $segment = is_string($path) && $path !== '' ? $path : (is_string($slug) ? $slug : '');
            if (! is_string($title) || $title === '' || $segment === '') {
                continue;
            }

            $item = ['title' => $title, 'url' => '/'.$segment];
            $published = $entry->getAttribute('published_at');
            if ($published instanceof \DateTimeInterface) {
                $item['date'] = $published->format('Y-m-d');
            }
            $items[] = $item;
        }

        return $items;
    }
}
