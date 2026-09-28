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
use Magna\Admin\PanelPath;
use Magna\Admin\PanelPathSwitcher;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\PagesSettings;
use Magna\Pages\Templates\TemplatePartResolver;
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
            'color_scheme' => $settings->color_scheme,
            'default_header_id' => $settings->default_header_id,
            'default_footer_id' => $settings->default_footer_id,
            'collection_mounts' => $settings->collection_mounts,
            'integrations' => $settings->integrations,
            // Core's, not this plugin's: where the panel answers is a core
            // concern, and it is offered here because freeing "/" only means
            // anything on a site that has something to serve there.
            'admin_prefix' => PanelPath::enabled(),
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

                Section::make('Site chrome')
                    ->description('The header and footer every page starts with. A page may choose its own in the builder, which overrides these.')
                    ->columns(2)
                    ->schema([
                        Select::make('default_header_id')
                            ->label('Default header')
                            ->helperText('Leave empty to use a published part whose handle is "header".')
                            ->options($this->chromeOptions('header'))
                            ->searchable()
                            ->nullable(),

                        Select::make('default_footer_id')
                            ->label('Default footer')
                            ->helperText('Leave empty to use a published part whose handle is "footer".')
                            ->options($this->chromeOptions('footer'))
                            ->searchable()
                            ->nullable(),

                        Select::make('color_scheme')
                            ->label('Colour scheme')
                            ->helperText('System follows each visitor’s own preference. Light and dark pin the site to one palette.')
                            ->options([
                                'system' => 'Follow the visitor’s preference',
                                'light' => 'Always light',
                                'dark' => 'Always dark',
                            ])
                            ->default('system')
                            ->required(),
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

                Section::make('Site root')
                    ->description('Who answers at the bare domain. Magna ships with the admin panel there, so the home page above is only reachable at its own URL until you hand the root to the site.')
                    ->schema([
                        Toggle::make('admin_prefix')
                            ->label('Serve the site at / and move the admin panel to /admin')
                            ->helperText('Saving this signs nobody out and changes no content — only the address of the panel. Bookmarks pointing at the old one stop working, and "/" starts serving the home page. From a shell: php artisan magna:panel:path --root puts it back.'),
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
        $settings->default_header_id = $this->stringOrNull($this->data['default_header_id'] ?? null);
        $settings->default_footer_id = $this->stringOrNull($this->data['default_footer_id'] ?? null);

        $scheme = $this->data['color_scheme'] ?? 'system';
        // The vocabulary is fixed and small, so anything else is not a
        // scheme and the site keeps following the visitor.
        $settings->color_scheme = in_array($scheme, ['system', 'light', 'dark'], true) ? $scheme : 'system';
        $settings->collection_mounts = $this->cleanMounts($this->data['collection_mounts'] ?? []);
        $settings->integrations = $this->cleanIntegrations($this->data['integrations'] ?? []);

        app(SettingsRepository::class)->persist($settings);

        // Home page / 404 / maintenance affect routing sitewide.
        app(PageCache::class)->flush();

        Notification::make()->title('Site settings saved')->success()->send();

        $this->applyPanelPath((bool) ($this->data['admin_prefix'] ?? false));
    }

    /**
     * Hand the domain root to the site, or take it back.
     *
     * Last, and separately: everything above is this plugin's own settings and
     * must be saved whatever happens to the panel's address. This moves the
     * URL the admin is standing on, so when it actually changes the only
     * honest thing to do is send them to where the panel now lives — the page
     * they are on ceases to exist the moment the next request is routed.
     */
    private function applyPanelPath(bool $adminPrefix): void
    {
        if ($adminPrefix === PanelPath::enabled()) {
            return;
        }

        $switcher = app(PanelPathSwitcher::class);
        $switcher->set($adminPrefix);

        Notification::make()
            ->title('The admin panel moved to '.$switcher->url())
            ->body($adminPrefix
                ? 'The site now answers at /. Update any bookmark pointing at the old panel address.'
                : 'The panel is back at the domain root, so the site no longer answers there.')
            ->success()
            ->persistent()
            ->send();

        $this->redirect($switcher->url());
    }

    /**
     * Published parts meant for a role, as select options.
     *
     * Asked of the resolver rather than queried here, so the settings
     * screen offers exactly what the renderer will accept.
     *
     * @return array<string, string>
     */
    private function chromeOptions(string $role): array
    {
        $options = [];
        foreach (app(TemplatePartResolver::class)->chromeChoices($role) as $choice) {
            // A draft is named rather than hidden, and says why it cannot
            // be the site default yet: only a published part renders.
            $options[$choice['id']] = $choice['published']
                ? $choice['title']
                : $choice['title'].' (draft — publish to use)';
        }

        return $options;
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
