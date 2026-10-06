<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Contracts\Auth\Authenticatable;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Conditions\DisplayConditionRegistry;
use Magna\Blocks\Icons\IconRegistry;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\Entry;
use Magna\Pages\Render\BindingResolver;
use Magna\Pages\Render\StyleDescriptors;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Themes\ThemeManager;

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
        private readonly BindingResolver $bindings,
        private readonly DisplayConditionRegistry $conditions,
        private readonly ThemeManager $themes,
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
                // The page's own settings, so the panel opens showing what
                // is set rather than fetching it a moment later.
                'settings' => is_array($settings = $entry->getAttribute('page_settings')) ? $settings : [],
            ],
            'registry' => $this->registryPayload($this->blocks),
            // How deep blocks may nest, so the builder refuses a drop the
            // save would refuse anyway. Shipped rather than mirrored in
            // TypeScript — the validator owns the number.
            'maxBlockDepth' => PageTreeValidator::MAX_BLOCK_DEPTH,
            'tokens' => $this->tokens->cssVariables(),
            'capabilities' => $this->capabilities($actor),
            // What a $bind may point at, for the inspector's picker.
            'bindingSources' => $this->bindings->sources(),
            // The style vocabulary, shipped rather than mirrored: the
            // renderer decides what `settings.style` may say, and the
            // inspector draws exactly those controls.
            'styleControls' => StyleDescriptors::forBuilder(),
            // Every show/hide rule this install can actually evaluate, for
            // the conditions picker. Shipped for the same reason as the
            // style vocabulary: the renderer decides what a condition type
            // means, so the builder must offer exactly what it will honour
            // rather than a list maintained beside it.
            'displayConditions' => $this->displayConditions(),
            /*
             * The icon vocabulary, shipped whole.
             *
             * The panel draws a tile per block and the inspector draws a
             * picker; both need the geometry, and a round trip per icon
             * would make either one crawl. It is a few kilobytes of
             * server-owned markup, which is the same trade the style
             * descriptor table already makes.
             */
            'icons' => app(IconRegistry::class)->all(),
            /*
             * The headers and footers this page may choose between, and
             * what it currently uses. Shipped with the document because a
             * page-level choice belongs to the page, and the panel should
             * not have to fetch a list to draw one select.
             */
            'chrome' => [
                'header' => app(TemplatePartResolver::class)->chromeChoices('header'),
                'footer' => app(TemplatePartResolver::class)->chromeChoices('footer'),
            ],
        ];
    }

    /**
     * What a freshly inserted block starts with, the active theme having its
     * say.
     *
     * Core's seed is computed from the schema, which is the only side that
     * knows what an `optionsFrom` select offers here — a builder seeding its
     * own would insert blocks the save then refuses. But core's seed is
     * generic by construction, and a theme's blocks are a design vocabulary:
     * dropping `features` into a timeline band seeded three cards about
     * nothing, which an author deleted before writing the real ones.
     *
     * Merged over, not substituted: a theme states only the keys it wants to
     * differ, and a key it says nothing about keeps whatever the schema
     * decided — including anything a later core release adds.
     *
     * @return array<string, mixed>
     */
    private function seedFor(BlockDefinition $block): array
    {
        $seed = $block->seedData();
        $themeSeed = $this->themes->active()?->blockSeeds[$block->handle] ?? null;

        return is_array($themeSeed) ? array_merge($seed, $themeSeed) : $seed;
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
                // Whether this block holds other blocks. The builder needs
                // it to know what may be dropped INTO — asking the server
                // rather than hardcoding handles is what lets a plugin ship
                // a container of its own.
                'container' => $block->container,
                // What a freshly inserted instance starts with. Computed
                // from the schema server-side because only this side knows
                // what an `optionsFrom` select offers here — a builder that
                // seeded its own would insert blocks the save then refuses.
                'seed' => $this->seedFor($block),
                // Which fields the canvas may edit in place, if this block
                // says. Absent means the first eligible one.
                'inlineFields' => $block->inlineFields,
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
            /*
             * RESOLVED options, not the static array.
             *
             * A select may name a ProvidesOptions class instead of listing
             * its choices, because the choices are not known until runtime
             * — the menus that exist, the content types installed, the data
             * sources enabled plugins registered. Sending $field->options
             * shipped an empty list for every one of those, so the Loop,
             * Navigation and Entries blocks could be inserted but never
             * configured: the dropdown had nothing in it.
             *
             * The Livewire editor already resolved these at render time;
             * this is the same call, on the payload the Vue builder reads.
             */
            'options' => $field->resolveOptions(),
            'multiple' => $field->multiple,
            // Which tab this field belongs on. Null means Content, which
            // is where every field was before a block could say otherwise.
            'group' => $field->group,
            // What a media field's picker may offer. Shipped for the same
            // reason as the style vocabulary: the server decides, and the
            // builder should ask for exactly what it will be given.
            'accept' => $field->accept,
            'fields' => array_map($this->fieldPayload(...), $field->fields),
        ];
    }

    /**
     * The condition types the builder may offer, built-ins first.
     *
     * The two built-ins are listed here rather than registered, because they
     * are matched before the registry and cannot be replaced — see
     * ConditionEvaluator. Listing them beside the plugin ones is what makes
     * the picker a single list instead of two that drift.
     *
     * A plugin condition arrives with nothing but a handle and a label: it
     * carries whatever settings it likes, and the builder cannot know their
     * shape. So the picker offers it, stores `{type: handle}`, and anything
     * further belongs to a future field descriptor — deliberately not
     * invented here, where it would be a guess at an API no plugin has asked
     * for yet.
     *
     * @return list<array{handle: string, label: string, builtIn: bool}>
     */
    private function displayConditions(): array
    {
        $conditions = [
            ['handle' => 'auth', 'label' => 'Visitor is signed in', 'builtIn' => true],
            ['handle' => 'schedule', 'label' => 'Between two dates', 'builtIn' => true],
        ];

        foreach ($this->conditions->all() as $condition) {
            $conditions[] = [
                'handle' => $condition->handle(),
                'label' => $condition->label(),
                'builtIn' => false,
            ];
        }

        return $conditions;
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
