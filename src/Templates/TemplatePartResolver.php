<?php

declare(strict_types=1);

namespace Magna\Pages\Templates;

use Magna\Blocks\PageTree;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;

/**
 * Resolves template parts — entries of the pages_template content type
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A3: templates are content, so
 * drafts, revisions, preview, and the block editor come free; header/footer
 * edits are draftable and reversible like any entry).
 *
 * v1 deviation from the plan's "admin-hidden" note, on purpose: the type is
 * VISIBLE in the admin so editors design headers/footers with the same
 * block editor and live preview pages get today. It hides behind the
 * builder's dedicated "Customize" surface when that ships.
 *
 * Parts are addressed by their slug ("header", "footer", or anything a ref
 * section points at). Only published parts render; a missing or drafted
 * part degrades to nothing.
 */
class TemplatePartResolver
{
    public function __construct(private readonly SchemaRegistry $schemaRegistry) {}

    /** Documents may not nest refs endlessly; one level is the contract. */
    private const MAX_REF_DEPTH = 1;

    public function partTree(string $handle): ?PageTree
    {
        if (! $this->schemaRegistry->has('pages_template')) {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entry::type('pages_template')
            ->where('slug', $handle)
            ->where('kind', 'part')
            ->where('status', EntryStatus::Published->value)
            ->first();

        if ($entry === null) {
            return null;
        }

        $document = $entry->getAttribute('blocks_data');

        return PageTree::fromArray(is_array($document) ? $document : []);
    }

    /**
     * Every published popup document, for site-wide overlay rendering and
     * the page-cache verdict. Shape: id, slug, title, document (raw
     * blocks_data — the §C9 conditions inside it gate visibility and
     * cacheability like any other document).
     *
     * @return list<array{id: string, slug: string, title: string, document: array<mixed, mixed>}>
     */
    public function popups(): array
    {
        if (! $this->schemaRegistry->has('pages_template')) {
            return [];
        }

        $popups = [];
        foreach (Entry::type('pages_template')
            ->where('kind', 'popup')
            ->where('status', EntryStatus::Published->value)
            ->orderBy('slug')
            ->get() as $entry) {
            $id = $entry->getKey();
            $slug = $entry->getAttribute('slug');
            if (! is_string($id) || ! is_string($slug) || $slug === '') {
                continue;
            }

            $document = $entry->getAttribute('blocks_data');
            $title = $entry->getAttribute('title');
            $popups[] = [
                'id' => $id,
                'slug' => $slug,
                'title' => is_string($title) ? $title : '',
                'document' => is_array($document) ? $document : [],
            ];
        }

        return $popups;
    }

    /**
     * Expand `ref` sections in a document by splicing the referenced part's
     * sections in place. Refs inside a referenced part are ignored (depth 1)
     * — reusable sections compose pages, they do not compose each other yet.
     *
     * @param  array<mixed, mixed>  $document
     * @return array<mixed, mixed>
     */
    public function expandRefs(array $document, int $depth = 0): array
    {
        if ($depth >= self::MAX_REF_DEPTH) {
            return $document;
        }

        $isWrapped = ! array_is_list($document) && is_array($document['sections'] ?? null);
        $sections = $isWrapped ? $document['sections'] : $document;

        if (! is_array($sections)) {
            return $document;
        }

        $expanded = [];
        foreach ($sections as $section) {
            if (! is_array($section) || ($section['type'] ?? null) !== 'ref') {
                $expanded[] = $section;

                continue;
            }

            $part = is_string($section['part'] ?? null) ? $this->partTree($section['part']) : null;
            if ($part === null) {
                continue; // missing part degrades to nothing
            }

            foreach ($part->toArray() as $partSection) {
                $expanded[] = $partSection;
            }
        }

        if ($isWrapped) {
            $document['sections'] = $expanded;

            return $document;
        }

        return $expanded;
    }
}
