<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Magna\Themes\ThemeAudit;

/**
 * Core's ThemeAudit, answered by the same auditor `magna:theme:check` runs.
 *
 * Bound by PagesPlugin only when core defines the contract — this file is
 * never loaded on a core that predates it.
 */
final class ThemePackageAudit implements ThemeAudit
{
    public function __construct(private readonly ThemeAuditor $auditor) {}

    public function errors(string $themeDirectory): array
    {
        $errors = [];

        foreach ($this->auditor->auditDirectory($themeDirectory) as $finding) {
            if ($finding['level'] === 'error') {
                $errors[] = $finding['file'].': '.$finding['message'];
            }
        }

        return $errors;
    }
}
