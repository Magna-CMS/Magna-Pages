<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Builder\FindsDocuments;
use Magna\Pages\Performance\PagePerformanceMeter;

/**
 * The builder's performance meter: weigh the CURRENT stored document
 * through the real render pipeline. Advisory only, like the a11y check.
 */
final class BuilderPerformanceController
{
    use FindsDocuments;

    public function __construct(private readonly PagePerformanceMeter $meter) {}

    public function measure(string $id): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json($this->meter->measure($this->findDocument($id)));
    }
}
