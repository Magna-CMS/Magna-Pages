<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Admin\Resources\EntryResource;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\ApprovalManager;

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

        $entry = $entries->create('pages_template', [
            'title' => $title,
            'kind' => 'part',
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
        $pages = Entry::type('page')
            ->orderByDesc('updated_at')
            ->get();

        $approvals = app(ApprovalManager::class);

        return [
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
            'templates' => Entry::type('pages_template')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Entry $template): array => [
                    'id' => (string) $template->getKey(),
                    'title' => (string) ($template->getAttribute('title') ?? 'Untitled'),
                    'slug' => (string) ($template->getAttribute('slug') ?? ''),
                    'kind' => (string) ($template->getAttribute('kind') ?? 'part'),
                    'status' => $template->status->value,
                    'builderUrl' => url('/pages-builder/edit/'.$template->getKey()),
                ])->all(),
        ];
    }
}
