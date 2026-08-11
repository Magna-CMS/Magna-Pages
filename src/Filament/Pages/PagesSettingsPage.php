<?php

declare(strict_types=1);

namespace Magna\Pages\Filament\Pages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use Magna\Pages\Cache\PageCache;
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
            'collection_mounts' => $settings->collection_mounts,
            'integrations' => $settings->integrations,
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

                Section::make('Collections on the site')
                    ->description('Mount a content type at a URL prefix: the prefix serves its archive, prefix/slug each entry. Only types whose schema declares publiclyRenderable are offered.')
                    ->schema([
                        Repeater::make('collection_mounts')
                            ->label('Mounted collections')
                            ->schema([
                                Select::make('type')
                                    ->label('Content type')
                                    ->options($this->renderableTypeOptions())
                                    ->required(),
                                TextInput::make('prefix')
                                    ->label('URL prefix')
                                    ->placeholder('blog')
                                    ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Mount a collection'),
                    ]),

                Section::make('Integrations & consent')
                    ->description('Third-party scripts on the public site. Necessary loads always; analytics and marketing stay inert until the visitor consents.')
                    ->schema([
                        Repeater::make('integrations')
                            ->label('Registered scripts')
                            ->schema([
                                TextInput::make('handle')
                                    ->label('Handle')
                                    ->placeholder('plausible')
                                    ->regex('/^[a-z0-9][a-z0-9-]*$/')
                                    ->required(),
                                TextInput::make('label')
                                    ->label('Label')
                                    ->placeholder('Plausible Analytics'),
                                Select::make('category')
                                    ->label('Consent category')
                                    ->options([
                                        'necessary' => 'Necessary (always loads)',
                                        'analytics' => 'Analytics (consent)',
                                        'marketing' => 'Marketing (consent)',
                                    ])
                                    ->required(),
                                TextInput::make('src')
                                    ->label('Script URL')
                                    ->placeholder('https://plausible.io/js/script.js')
                                    ->url()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Register a script'),
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
        $settings->collection_mounts = $this->cleanMounts($this->data['collection_mounts'] ?? []);
        $settings->integrations = $this->cleanIntegrations($this->data['integrations'] ?? []);

        app(SettingsRepository::class)->persist($settings);

        // Home page / 404 / maintenance affect routing sitewide.
        app(PageCache::class)->flush();

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

    /**
     * Content types the schema allows on the public site (§C4).
     *
     * @return array<string, string>
     */
    private function renderableTypeOptions(): array
    {
        $options = [];
        foreach (app(SchemaRegistry::class)->all() as $type) {
            if ($type->publiclyRenderable && $type->handle !== 'page') {
                $options[$type->handle] = $type->displayName;
            }
        }
        ksort($options);

        return $options;
    }

    /**
     * @return list<array{handle: string, label: string, category: string, src: string}>
     */
    private function cleanIntegrations(mixed $integrations): array
    {
        if (! is_array($integrations)) {
            return [];
        }

        $clean = [];
        foreach ($integrations as $integration) {
            if (is_array($integration)
                && is_string($integration['handle'] ?? null)
                && preg_match('/^[a-z0-9][a-z0-9-]*$/', $integration['handle']) === 1
                && is_string($integration['src'] ?? null)
                && str_starts_with($integration['src'], 'https://')
                && in_array($integration['category'] ?? null, ['necessary', 'analytics', 'marketing'], true)
            ) {
                $clean[] = [
                    'handle' => $integration['handle'],
                    'label' => is_string($integration['label'] ?? null) && $integration['label'] !== ''
                        ? $integration['label']
                        : $integration['handle'],
                    'category' => $integration['category'],
                    'src' => $integration['src'],
                ];
            }
        }

        return $clean;
    }

    /**
     * @return list<array{type: string, prefix: string}>
     */
    private function cleanMounts(mixed $mounts): array
    {
        if (! is_array($mounts)) {
            return [];
        }

        $clean = [];
        foreach ($mounts as $mount) {
            if (is_array($mount)
                && is_string($mount['type'] ?? null) && $mount['type'] !== ''
                && is_string($mount['prefix'] ?? null)
                && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $mount['prefix']) === 1
            ) {
                $clean[] = ['type' => $mount['type'], 'prefix' => $mount['prefix']];
            }
        }

        return $clean;
    }
}
