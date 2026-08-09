<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Themes\StyleManager;
use Magna\Pages\Themes\ThemeTokens;

/**
 * The Design tab's server half: what the theme declares, what the site has
 * overridden, and the one write that changes it. Writes are design-tier —
 * a palette change restyles every page at once, which is a bigger blast
 * radius than any single-page edit.
 */
final class BuilderStylesController
{
    public function __construct(
        private readonly ThemeTokens $tokens,
        private readonly StyleManager $styles,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize('pages.content');

        return response()->json([
            'theme' => $this->tokens->themeVariables(),
            'overrides' => $this->styles->overrides(),
            'effective' => $this->tokens->cssVariables(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('pages.design');

        $request->validate(['tokens' => ['present', 'array']]);

        try {
            $stored = $this->styles->put(
                (array) $request->input('tokens'),
                is_string($request->user()?->getAuthIdentifier()) ? $request->user()->getAuthIdentifier() : null,
            );
        } catch (PatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'overrides' => $stored,
            'effective' => $this->tokens->cssVariables(),
        ]);
    }
}
