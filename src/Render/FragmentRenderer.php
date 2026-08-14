<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\Entry;
use Magna\Pages\Themes\ThemeViewResolver;

/**
 * Renders ONE node of a document, for the builder's fragment loop
 * (docs/magna-pages/03-BUILDER.md §2).
 *
 * When a block's data changes the canvas does not reload the page: it asks
 * for that block's HTML and morph-swaps it in. The HTML comes from the same
 * view, the same resolve seam and the same marker pass the full page render
 * uses, so a fragment-swapped canvas and a freshly loaded one are the same
 * document — there is no second rendering path to keep in sync.
 *
 * The document arrives in the request (unsaved editor state), which is why
 * the caller must authorize the actor before handing anything here.
 */
final class FragmentRenderer
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockDataResolver $resolver,
        private readonly ThemeViewResolver $themeViews,
        private readonly BuilderMarkup $markup,
        private readonly BindingResolver $bindings,
    ) {}

    /**
     * @param  array<mixed, mixed>  $document
     * @return string|null null when the node is not in the document, or its
     *                     block handle has no view (a disabled plugin)
     */
    public function renderBlock(array $document, string $nodeId, bool $builderMode = true, ?Entry $context = null): ?string
    {
        $block = $this->findBlock(PageTree::fromArray($document), $nodeId);
        if ($block === null) {
            return null;
        }

        if ($this->themeViews->blockView($block->block) === null) {
            return null;
        }

        // The SAME partial the full page render uses, so a fragment carries
        // whatever that node carries there: its style class, its builder
        // markers, its binding-resolved data — and, for a container, its
        // children rendered through this same recursion. Rendering the
        // block's view directly here (as this once did) made containers
        // swap in empty and blocks lose their styling class.
        return view('magna-pages::partials.block', [
            'block' => $block,
            'blockViewFor' => fn (string $handle): ?string => $this->themeViews->blockView($handle),
            'registry' => $this->registry,
            'resolver' => $this->resolver,
            'mark' => $builderMode
                ? fn (string $html, string $id, string $kind): string => $this->markup->mark($html, $id, $kind)
                : fn (string $html, string $id, string $kind): string => $html,
            // The builder shows every node — you cannot edit what you
            // cannot see — matching PageRenderer's builder-mode stance.
            'passes' => fn (array $settings): bool => true,
            'bound' => fn (BlockNode $node): BlockNode => $node->withData(
                $this->bindings->resolve($node->data, $context),
            ),
            // A fragment replaces one element; it cannot add rules to the
            // page's stylesheet, which the full render already emitted.
            'collectCss' => static function (string $css): void {},
        ])->render();
    }

    private function findBlock(PageTree $tree, string $nodeId): ?BlockNode
    {
        foreach ($tree->sections as $section) {
            foreach ($section->columns as $column) {
                $found = $this->searchBlocks($column->blocks, $nodeId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<BlockNode>  $blocks
     */
    private function searchBlocks(array $blocks, string $nodeId): ?BlockNode
    {
        foreach ($blocks as $block) {
            if ($block->id === $nodeId) {
                return $block;
            }

            $found = $this->searchBlocks($block->children, $nodeId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
