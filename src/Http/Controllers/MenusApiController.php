<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;

/**
 * Delivery surface for menus: the resolved link tree headless frontends
 * consume (docs/magna-pages/05 §2).
 */
final class MenusApiController
{
    public function __construct(private readonly MenuManager $menus) {}

    public function __invoke(string $handle): JsonResponse
    {
        $menu = Menu::query()->where('handle', $handle)->first();

        if ($menu === null) {
            return response()->json(['message' => 'Menu not found.'], 404);
        }

        return response()->json([
            'handle' => $menu->handle,
            'name' => $menu->name,
            'items' => $this->menus->resolve($handle),
        ]);
    }
}
