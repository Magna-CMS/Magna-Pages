<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Admin\Resources\EntryResource;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\ApprovalManager;
use Magna\Pages\Builder\ChromeStarter;
use Magna\Pages\Cache\PageCache;

/**
 * The Pages screen: every page on the site, with the three doors an editor
 * actually wants — open the visual builder, edit the fields, create a new
 * page. The generic Content > Page resource still exists; this screen is
 * the site-centric view of the same entries, living where Menus and Site
 * settings already do.
 */
class PagesIndexPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $title = 'Pages';

    protected static ?string $slug = 'pages-index';

    protected static ?int $navigationSort = -1;

    protected string $view = 'magna-pages::filament.pages-index';

    public string $newPageTitle = '';

    public string $newPartTitle = '';

    /**
     * What the new part is FOR: generic, or the site's header or footer.
     *
     * Asked at creation rather than left to be set later, because a part
     * with no role is invisible to the chrome pickers — a header nobody
     * can choose is a header nobody made.
     */
    public string $newPartRole = 'generic';

    /** Narrows the page list. Templates live below it, so its length matters. */
    public string $pageSearch = '';

    /**
     * How many pages the shortcut list shows.
     *
     * A shortcut to recent work, deliberately — the full index is the
     * entry resource, which paginates and sorts properly.
     */
    private const PAGE_LIMIT = 25;

    public string $newPopupTitle = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.content') ?? false;
    }

    /**
     * Create a draft page from a title alone and jump straight into the
     * builder — the shortest path from "I need a page" to editing it.
     */
    public function createPage(EntryManager $entries): void
    {
        $title = trim($this->newPageTitle);
        if ($title === '') {
            Notification::make()->title('Give the page a title first.')->warning()->send();

            return;
        }

        $entry = $entries->create('page', [
            'title' => $title,
            'blocks_data' => [],
        ], auth()->id() !== null ? (string) auth()->id() : null);

        $this->redirect(url('/pages-builder/edit/'.$entry->getKey()));
    }

    /**
     * Create a template part (slug "header"/"footer" are the live slots)
     * and open it in the builder — Customize-header in two clicks.
     */
    public function createPart(EntryManager $entries): void
    {
        $title = trim($this->newPartTitle);
        if ($title === '') {
            Notification::make()->title('Give the template a title first.')->warning()->send();

            return;
        }

        $role = in_array($this->newPartRole, ['generic', 'header', 'footer'], true)
            ? $this->newPartRole
            : 'generic';

        $entry = $entries->create('pages_template', [
            'title' => $title,
            'kind' => 'part',
            'role' => $role,
            // Chrome starts from a starter, the same one the builder's own
            // "Design a header" offers. Two on-ramps to the same thing that
            // hand back different documents is a difference nobody can
            // explain — and an EMPTY header renders nothing at all, so a
            // site that published one would wonder why nothing changed.
            'blocks_data' => $role === 'generic' ? [] : ChromeStarter::document($role),
        ], auth()->id() !== null ? (string) auth()->id() : null);

        $this->redirect(url('/pages-builder/edit/'.$entry->getKey()));
    }

    /**
     * Turn a piece of chrome's sticky behaviour on or off.
     *
     * Here rather than in the builder because it is a property of the PART,
     * not of anything inside it — there is no node in the document to
     * select and no panel that would obviously own it.
     */
    public function toggleSticky(string $id): void
    {
        /** @var Entry|null $entry */
        $entry = Entry::type('pages_template')->find($id);
        if ($entry === null || ! in_array($entry->getAttribute('role'), ['header', 'footer'], true)) {
            return;
        }

        $behaviour = $entry->getAttribute('behaviour');
        $behaviour = is_array($behaviour) ? $behaviour : [];
        $behaviour['sticky'] = ($behaviour['sticky'] ?? false) !== true;

        $entry->setAttribute('behaviour', $behaviour);
        $entry->save();

        // Chrome is on every page, so its behaviour is too.
        app(PageCache::class)->flush();

        Notification::make()
            ->title($behaviour['sticky'] ? 'Sticks to the top now' : 'Scrolls with the page now')
            ->success()
            ->send();
    }

    /**
     * Create a popup document (renders site-wide as a dismissible overlay
     * once published; its Show-when conditions decide who sees it).
     */
    public function createPopup(EntryManager $entries): void
    {
        $title = trim($this->newPopupTitle);
        if ($title === '') {
            Notification::make()->title('Give the popup a title first.')->warning()->send();

            return;
        }

        $entry = $entries->create('pages_template', [
            'title' => $title,
            'kind' => 'popup',
            'blocks_data' => [],
        ], auth()->id() !== null ? (string) auth()->id() : null);

        $this->redirect(url('/pages-builder/edit/'.$entry->getKey()));
    }

    /**
     * Rows for the view.
     *
     * @return list<array<string, mixed>>
     */
    protected function getViewData(): array
    {
        /*
         * BOUNDED, and searchable.
         *
         * This used to fetch every page on the site and render all of
         * them. On a site with a few hundred pages that is a slow query,
         * a huge document, and — because the templates section sits below
         * this table — a header nobody can scroll to. The list is a
         * shortcut to recent work, not the canonical index; the full one
         * is the entry resource, linked from the view.
         */
        $query = Entry::type('page');

        $search = trim($this->pageSearch);
        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();

        $pages = $query->orderByDesc('updated_at')->limit(self::PAGE_LIMIT)->get();

        $approvals = app(ApprovalManager::class);

        return [
            'pageTotal' => $total,
            'pageLimit' => self::PAGE_LIMIT,
            'pages' => $pages->map(fn (Entry $page): array => [
                'id' => (string) $page->getKey(),
                'title' => (string) ($page->getAttribute('title') ?? 'Untitled'),
                'path' => $page->path ?? $page->getAttribute('slug'),
                'status' => $page->status->value,
                'updated' => $page->updated_at?->diffForHumans() ?? '',
                'pendingApproval' => $approvals->pendingFor((string) $page->getKey()) !== null,
                'builderUrl' => url('/pages-builder/edit/'.$page->getKey()),
                'editUrl' => EntryResource::getUrl('edit', ['record' => $page->getKey(), 'type' => 'page']),
                'viewUrl' => $page->status->value === 'published' && $page->path !== null
                    ? url('/'.$page->path)
                    : null,
            ])->all(),
            'createFieldsUrl' => EntryResource::getUrl('create', ['type' => 'page']),
            // The canonical index, for when the shortcut list is not enough.
            'allPagesUrl' => EntryResource::getUrl('index', ['type' => 'page']),
            'templates' => Entry::type('pages_template')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Entry $template): array => [
                    'id' => (string) $template->getKey(),
                    'title' => (string) ($template->getAttribute('title') ?? 'Untitled'),
                    'slug' => (string) ($template->getAttribute('slug') ?? ''),
                    'kind' => (string) ($template->getAttribute('kind') ?? 'part'),
                    'role' => (string) ($template->getAttribute('role') ?? 'generic'),
                    'sticky' => is_array($behaviour = $template->getAttribute('behaviour'))
                        && ($behaviour['sticky'] ?? false) === true,
                    'status' => $template->status->value,
                    'builderUrl' => url('/pages-builder/edit/'.$template->getKey()),
                ])->all(),
        ];
    }
}
