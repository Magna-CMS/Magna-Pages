<?php

declare(strict_types=1);

namespace Magna\Pages\Tests\Feature;

use Illuminate\Support\Facades\File;
use Magna\Pages\Themes\ThemeAuditor;
use Magna\Testing\PluginTestCase;

/**
 * A theme may print `$headHtml` raw — it is part of the layout contract from
 * 0.8.0 — with or without an empty-string fallback for older Pages. Every
 * other raw echo is still an error.
 */
final class HeadContractThemeCheckTest extends PluginTestCase
{
    protected string $plugin = 'magna-cms/pages';

    private string $theme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theme = storage_path('framework/testing/head-theme-'.uniqid());
        File::ensureDirectoryExists($this->theme.'/views/system');
        File::put($this->theme.'/theme.json', json_encode(['name' => 'acme/head', 'displayName' => 'Head', 'version' => '1.0.0']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->theme);

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function errorsFor(string $layout): array
    {
        File::put($this->theme.'/views/system/layout.blade.php', $layout);

        return array_values(array_column(array_filter(
            $this->app->make(ThemeAuditor::class)->auditDirectory($this->theme),
            static fn (array $finding): bool => $finding['level'] === 'error',
        ), 'message'));
    }

    public function test_head_html_may_be_printed_raw(): void
    {
        $this->assertSame([], $this->errorsFor('<head>{!! $headHtml !!}</head>'));
        $this->assertSame([], $this->errorsFor("<head>{!! \$headHtml ?? '' !!}</head>"));
        $this->assertSame([], $this->errorsFor("<head>@unless(\$headProvides['title'] ?? false)<title>{{ \$title }}</title>@endunless</head>"));
    }

    public function test_other_raw_echoes_are_still_rejected(): void
    {
        $this->assertNotSame([], $this->errorsFor('<head>{!! $secrets !!}</head>'));
        $this->assertNotSame([], $this->errorsFor("<head>{!! \$secrets ?? '' !!}</head>"));
        $this->assertNotSame([], $this->errorsFor('<head>{!! $headHtml ?? $secrets !!}</head>'));
        $this->assertNotSame([], $this->errorsFor('<head>{!! $headHtmlExtra !!}</head>'));
    }
}
