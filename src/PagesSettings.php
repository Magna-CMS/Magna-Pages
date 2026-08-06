<?php

declare(strict_types=1);

namespace Magna\Pages;

use Magna\Settings\Settings;

/**
 * Pages-domain site settings (settings group "pages" — audited, cached,
 * admin-editable via the core Settings infrastructure).
 *
 * Scope rule (10-REVIEW-RESOLUTIONS §A8): only Pages-domain scalars live
 * here. Host/URL facts (frontend URL, preview base, CDN) remain in core
 * UrlSettings — one owner for where the site lives, no drift.
 */
class PagesSettings extends Settings
{
    /** Entry id (page type) served at the site root; null = no home page yet. */
    public ?string $home_page_id = null;

    /** Entry id (page type) rendered for unknown URLs; null = plain 404. */
    public ?string $not_found_page_id = null;

    /** When true, the public site serves the maintenance holding page. */
    public bool $maintenance_mode = false;
}
