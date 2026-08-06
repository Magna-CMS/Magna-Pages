<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Pages\PagesSettings;
use Magna\Settings\SettingsRepository;

/**
 * Site settings for the Pages plugin: home page, 404 page, maintenance mode.
 * Backed by the core Settings infrastructure (typed PagesSettings class) —
 * audited and cached like every other settings group.
 */
class PagesSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Site settings';

    protected static ?string $title = 'Site settings';

    protected static ?string $slug = 'pages-settings';

    protected string $view = 'magna-pages::filament.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Without this the page is reachable by URL to anyone who can open the
     * panel, whatever the navigation shows.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('pages.settings') ?? false;
    }

    public function mount(): void
    {
        $settings = PagesSettings::get();

        $this->form->fill([
            'home_page_id' => $settings->home_page_id,
            'not_found_page_id' => $settings->not_found_page_id,
            'maintenance_mode' => $settings->maintenance_mode,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Site structure')
                    ->columns(2)
                    ->schema([
                        Select::make('home_page_id')
                            ->label('Home page')
                            ->helperText('The page served at the site root.')
                            ->options($this->pageOptions())
                            ->searchable()
                            ->nullable(),

                        Select::make('not_found_page_id')
                            ->label('404 page')
                            ->helperText('Shown for unknown URLs. Leave empty for a plain 404.')
                            ->options($this->pageOptions())
                            ->searchable()
                            ->nullable(),
                    ]),

                Section::make('Availability')
                    ->schema([
                        Toggle::make('maintenance_mode')
                            ->label('Maintenance mode')
                            ->helperText('Serves the maintenance holding page to all visitors. The admin panel stays reachable.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $settings = PagesSettings::get();
        $settings->home_page_id = $this->stringOrNull($this->data['home_page_id'] ?? null);
        $settings->not_found_page_id = $this->stringOrNull($this->data['not_found_page_id'] ?? null);
        $settings->maintenance_mode = (bool) ($this->data['maintenance_mode'] ?? false);

        app(SettingsRepository::class)->persist($settings);

        Notification::make()->title('Site settings saved')->success()->send();
    }

    /**
     * Published pages as select options. Empty until the page type exists
     * (plugin enabled + schema synced).
     *
     * @return array<string, string>
     */
    private function pageOptions(): array
    {
        if (! app(SchemaRegistry::class)->has('page')) {
            return [];
        }

        $options = [];
        foreach (Entry::type('page')
            ->where('status', EntryStatus::Published->value)
            ->orderBy('title')
            ->limit(200)
            ->get() as $entry) {
            $id = $entry->getKey();
            $title = $entry->getAttribute('title');
            if (is_string($id) && is_string($title)) {
                $options[$id] = $title;
            }
        }

        return $options;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
