<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Magna\Pages\Experiments\ExperimentTracker;

/**
 * The public counter endpoint for A/B sections. Thin by rule; the tracker
 * owns validation and the increment.
 *
 * It answers 204 either way — a caller learns nothing about which
 * experiments exist, and a page that keeps counting after an experiment
 * was deleted sees no errors.
 */
final class ExperimentController
{
    public function __construct(private readonly ExperimentTracker $tracker) {}

    public function track(Request $request): JsonResponse
    {
        $this->tracker->record(
            (string) $request->input('experiment', ''),
            (string) $request->input('variant', ''),
            (string) $request->input('event', ''),
        );

        return response()->json(null, 204);
    }
}
