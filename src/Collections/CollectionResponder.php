<?php

declare(strict_types=1);

namespace Magna\Pages\Collections;

use Illuminate\Http\Request;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Pages\Render\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves mounted collections (05-SITE-STRUCTURE §1): the mount prefix
 * renders the type's archive, prefix/{slug} the single entry — both inside
 * the active layout shell.
 *
 * A single entry renders through the site-designed template document
 * (pages_template kind=collection, slug "{type}-single") when one is
 * published — bindings resolve against the entry, and §C4 decides which
 * fields a binding may read. Without a template, a built-in minimal view
 * renders the entry's PUBLIC fields only. The archive uses the built-in
 * list view in v1 (a designable archive document needs the
 * collection.current data source — deferred with it).
 *
 * Pagination is capped (§C4): page size fixed at 24, deep offsets bounded.
 */
final class CollectionResponder
{
    private const PAGE_SIZE = 24;

    private const MAX_PAGE = 100;

    public function __construct(
        private readonly CollectionMounts $mounts,
        private readonly PageRenderer $renderer,
        private readonly CollectionTemplates $templates,
    ) {}

    /** The response for the path, or null when no mount claims it. */
    public function respond(string $path, Request $request): ?Response
    {
        $match = $this->mounts->match($path);
        if ($match === null) {
            return null;
        }

        $contentType = $this->mounts->contentType($match['type']);
        if ($contentType === null) {
            return null;
        }

        return $match['mode'] === 'archive'
            ? $this->archive($match, $contentType, $request)
            : $this->single($match, $contentType, (string) $match['slug']);
    }

    /**
     * @param  array{mode: string, type: string, prefix: string, slug: string|null}  $mount
     */
    private function archive(array $mount, ContentType $contentType, Request $request): Response
    {
        $page = max(1, min(self::MAX_PAGE, (int) $request->query('page', '1')));

        $entries = Entry::type($mount['type'])
            ->where('status', EntryStatus::Published->value)
            ->orderByDesc('published_at')
            ->forPage($page, self::PAGE_SIZE)
            ->get();

        $items = [];
        foreach ($entries as $entry) {
            $item = $this->publicItem($entry, $contentType, $mount['prefix']);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $mainHtml = view('magna-pages::collections.archive', [
            'typeLabel' => $contentType->displayName,
            'items' => $items,
            'page' => $page,
            'hasMore' => count($entries) === self::PAGE_SIZE,
            'prefix' => $mount['prefix'],
        ])->render();

        $html = $this->renderer->renderDocument([], $contentType->displayName, mainHtml: $mainHtml);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param  array{mode: string, type: string, prefix: string, slug: string|null}  $mount
     */
    private function single(array $mount, ContentType $contentType, string $slug): ?Response
    {
        /** @var Entry|null $entry */
        $entry = Entry::type($mount['type'])
            ->where('slug', $slug)
            ->where('status', EntryStatus::Published->value)
            ->first();

        if ($entry === null) {
            return null; // fall through to redirects / 404
        }

        $title = $entry->getAttribute('title');
        $title = is_string($title) ? $title : $contentType->displayName;

        // Site-designed single template wins; bindings resolve against the
        // entry (§C4 gates which fields a binding may read).
        $template = $this->templates->singleDocument($mount['type']);
        if ($template !== null) {
            $html = $this->renderer->renderDocument($template, $title, context: $entry);
        } else {
            $item = $this->publicItem($entry, $contentType, $mount['prefix']);
            $mainHtml = view('magna-pages::collections.single', [
                'title' => $title,
                'item' => $item ?? ['fields' => []],
            ])->render();
            $html = $this->renderer->renderDocument([], $title, mainHtml: $mainHtml);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * The entry reduced to what the public may see: title, URL, date, and
     * ONLY fields flagged publicOnFrontend (§C4) — shaped as presentation
     * strings, never model state.
     *
     * @return array{title: string, url: string, date: string|null, fields: array<string, string>}|null
     */
    private function publicItem(Entry $entry, ContentType $contentType, string $prefix): ?array
    {
        $title = $entry->getAttribute('title');
        $slug = $entry->getAttribute('slug');
        if (! is_string($title) || $title === '' || ! is_string($slug) || $slug === '') {
            return null;
        }

        $fields = [];
        foreach ($contentType->fields as $field) {
            if (! $field->publicOnFrontend() || in_array($field->handle, ['title', 'slug'], true)) {
                continue;
            }
            $value = $entry->getAttribute($field->handle);
            if ($value instanceof \DateTimeInterface) {
                $fields[$field->handle] = $value->format('Y-m-d');
            } elseif (is_string($value) || is_numeric($value)) {
                $fields[$field->handle] = (string) $value;
            }
        }

        $published = $entry->getAttribute('published_at');

        return [
            'title' => $title,
            'url' => '/'.$prefix.'/'.$slug,
            'date' => $published instanceof \DateTimeInterface ? $published->format('Y-m-d') : null,
            'fields' => $fields,
        ];
    }
}
