<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

/**
 * What a single builder patch operation actually does to the document
 * (10-REVIEW-RESOLUTIONS.md §C3). The classification is what client-safe
 * mode is enforced on: a content-only editor may retype a heading but may
 * not delete the section it sits in, and that distinction has to be decided
 * on the server from the operation itself — never from what the UI claims
 * it was doing.
 */
enum PatchKind: string
{
    /** Field data inside a block: text, image ids, select values. */
    case Content = 'content';

    /** Adding, removing, moving or reordering nodes. */
    case Structure = 'structure';

    /** Presentation settings on a node: spacing, colors, token overrides. */
    case Style = 'style';

    /** A dynamic binding (`$bind`) replacing a literal value. */
    case Binding = 'binding';

    /** Display conditions on a node. */
    case Condition = 'condition';

    /** Node lock state (who may edit what). */
    case Lock = 'lock';

    /** The permission a given kind of edit requires. */
    public function permission(): string
    {
        return match ($this) {
            self::Content => 'pages.content',
            self::Structure => 'pages.layout',
            self::Style, self::Binding, self::Condition, self::Lock => 'pages.design',
        };
    }
}
