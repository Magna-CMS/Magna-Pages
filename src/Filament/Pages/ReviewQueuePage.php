<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Content\Entry;
use Magna\Pages\Builder\ApprovalManager;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Builder\PublishRequest;
use Magna\Users\User;

/**
 * The reviewer's desk: every pending publish request, oldest first —
 * the queue is a promise to whoever asked first.
 *
 * Approve publishes on the spot (ApprovalManager's one-action rule);
 * Return requires the note the requester will actually read. Both go
 * through the same manager as the HTTP API, so the two surfaces cannot
 * drift on the rules.
 */
class ReviewQueuePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $title = 'Review queue';

    protected static ?string $slug = 'pages-review-queue';

    protected string $view = 'magna-pages::filament.review-queue';

    /** Return notes being typed, keyed by request id. @var array<string, string> */
    public array $returnNotes = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.publish') ?? false;
    }

    public function approve(string $requestId, ApprovalManager $approvals): void
    {
        $request = PublishRequest::query()->find($requestId);
        if ($request === null) {
            return;
        }

        try {
            $approvals->approve($request, $this->reviewer());
            Notification::make()->title('Published.')->success()->send();
        } catch (PatchException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function returnRequest(string $requestId, ApprovalManager $approvals): void
    {
        $request = PublishRequest::query()->find($requestId);
        if ($request === null) {
            return;
        }

        try {
            $approvals->return($request, $this->reviewer(), $this->returnNotes[$requestId] ?? '');
            unset($this->returnNotes[$requestId]);
            Notification::make()->title('Returned to the editor.')->success()->send();
        } catch (PatchException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $pending = PublishRequest::query()
            ->where('status', PublishRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        $pages = Entry::type('page')->findMany($pending->pluck('entry_id'))->keyBy(
            fn (Entry $entry): string => (string) $entry->getKey(),
        );
        $requesters = User::query()->findMany($pending->pluck('requested_by'))->keyBy('id');

        return [
            'requests' => $pending->map(fn (PublishRequest $request): array => [
                'id' => $request->id,
                'title' => (string) ($pages[$request->entry_id]?->getAttribute('title') ?? 'Deleted page'),
                'note' => $request->note,
                'requester' => $requesters[$request->requested_by]?->name ?? 'Unknown user',
                'age' => $request->created_at?->diffForHumans() ?? '',
                'builderUrl' => url('/pages-builder/edit/'.$request->entry_id),
            ])->all(),
        ];
    }

    private function reviewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
