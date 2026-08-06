<?php

declare(strict_types=1);

namespace Magna\Pages\Menus;

use Magna\Blocks\Resolution\ResolvesBlockData;

/**
 * Resolves the nav block: menu handle → nested link tree
 * (views receive data; they never query).
 */
class NavBlockResolver implements ResolvesBlockData
{
    public function __construct(private readonly MenuManager $menus) {}

    public function handle(): string
    {
        return 'nav';
    }

    public function resolve(array $data): array
    {
        $handle = $data['menu'] ?? null;

        return [
            'items' => is_string($handle) && $handle !== '' ? $this->menus->resolve($handle) : [],
        ];
    }
}
