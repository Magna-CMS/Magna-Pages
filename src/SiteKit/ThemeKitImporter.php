<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Magna\Themes\ThemeContentImporter;

/**
 * The page builder's answer to core's "install a theme with demo data":
 * apply a theme's starter kit adding only what the site lacks.
 *
 * Bound by PagesPlugin only when core defines the contract — this file is
 * never loaded on a core that predates it.
 */
final class ThemeKitImporter implements ThemeContentImporter
{
    public function __construct(private readonly SiteKit $kits) {}

    public function importMissing(array $kit, ?string $actorId = null): array
    {
        return $this->kits->applyMissing($kit, $actorId);
    }
}
