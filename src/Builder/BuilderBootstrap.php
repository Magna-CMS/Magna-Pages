<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Contracts\Auth\Authenticatable;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Entry;
use Magna\Pages\Themes\ThemeTokens;

/**
 * The single payload the builder loads on open: the document, the block
 * catalog it may use, the design tokens it renders against, and the
 * capabilities this actor actually has.
 *
 * One request rather than four, because the SPA cannot draw anything useful
 * until it has all of them — and because the capability set has to arrive
 * from the same place as the data it describes, so the UI can grey out what
 * the server would refuse anyway. The greying out is courtesy; the refusal
 * is PatchAuthorizer.
 */
final class BuilderBootstrap
{
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ThemeTokens $tokens,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEntry(Entry $entry, ?Authenticatable $actor): array
    {
        $document = $entry->getAttribute(DocumentEditor::FIELD);

        return [
            'document' => [
                'id' => $entry->getKey(),
                'title' => $entry->getAttribute('title'),
                'slug' => $entry->getAttribute('slug'),
                'path' => $entry->path,
                'status' => $entry->status->value,
                'updated_at' => $entry->updated_at?->toIso8601String(),
                'blocks' => is_array($document) ? $document : [],
            ],
            'registry' => $this->registryPayload($this->blocks),
            'tokens' => $this->tokens->cssVariables(),
            'capabilities' => $this->capabilities($actor),
        ];
    }

    /**
     * The installed blocks, in the shape the Add panel and the schema-driven
     * inspector both read.
     *
     * @return list<array<string, mixed>>
     */
    public function registryPayload(BlockRegistry $registry): array
    {
        return array_map(
            fn (BlockDefinition $block): array => [
                'handle' => $block->handle,
                'label' => $block->label,
                'icon' => $block->icon,
                'category' => $block->category,
                'requiresPermission' => $block->requiresPermission,
                'fields' => array_map($this->fieldPayload(...), $block->fields),
            ],
            array_values($registry->all()),
        );
    }

    /** @return array<string, mixed> */
    private function fieldPayload(BlockField $field): array
    {
        return [
            'handle' => $field->handle,
            'type' => $field->type,
            'label' => $field->label,
            'required' => $field->required,
            'default' => $field->default,
            'options' => $field->options,
            'multiple' => $field->multiple,
            'fields' => array_map($this->fieldPayload(...), $field->fields),
        ];
    }

    /**
     * What this actor may do, named the same way PatchKind names it — the UI
     * and the authorizer speak one vocabulary.
     *
     * @return array<string, bool>
     */
    private function capabilities(?Authenticatable $actor): array
    {
        $can = static fn (string $permission): bool => $actor?->can($permission) ?? false;

        return [
            'content' => $can('pages.content'),
            'structure' => $can('pages.layout'),
            'style' => $can('pages.design'),
            'publish' => $can('pages.publish'),
        ];
    }
}
