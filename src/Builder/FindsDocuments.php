<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Content\Entry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The builder edits block documents, and two content types carry them:
 * pages, and template documents (header/footer parts, page templates —
 * §A3). One finder, so every builder surface agrees on what is editable
 * and a third documented type is one line here rather than a hunt.
 */
trait FindsDocuments
{
    /** Content types whose entries the builder may open. */
    private const DOCUMENT_TYPES = ['page', 'pages_template'];

    private function findDocument(string $id): Entry
    {
        foreach (self::DOCUMENT_TYPES as $type) {
            $entry = Entry::type($type)->find($id);
            if ($entry !== null) {
                return $entry;
            }
        }

        throw new NotFoundHttpException('Document not found.');
    }
}
