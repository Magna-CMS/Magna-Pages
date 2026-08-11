<?php

declare(strict_types=1);

namespace Magna\Pages\Frontend;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Magna\Frontend\FrontendPage;

/**
 * The one rule for who may open a plugin frontend page — shared by the
 * responder (enforce before render) and the menu builder (hide items the
 * visitor could not open). Deliberately dependency-free: MenuManager and
 * the render pipeline both need it, and anything heavier here recreates
 * the MenuManager → renderer cycle.
 */
final class FrontendPageVisibility
{
    public function visibleTo(FrontendPage $page, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return ! $page->requiresAuth && $page->permission === null;
        }

        return $page->permission === null || Gate::forUser($user)->allows($page->permission);
    }
}
