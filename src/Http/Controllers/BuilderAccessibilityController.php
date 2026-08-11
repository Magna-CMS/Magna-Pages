<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Accessibility\DocumentAccessibility;
use Magna\Pages\Builder\FindsDocuments;

/**
 * The builder's accessibility check: run the advisory checker over the
 * CURRENT stored document and return findings. Advisory only — nothing
 * here gates saving or publishing.
 */
final class BuilderAccessibilityController
{
    use FindsDocuments;

    public function __construct(private readonly DocumentAccessibility $checker) {}

    public function check(string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        $entry = $this->findDocument($id);
        $document = $entry->getAttribute('blocks_data');

        return response()->json([
            'findings' => $this->checker->check(is_array($document) ? $document : []),
        ]);
    }
}
