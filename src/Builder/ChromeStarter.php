<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Support\Str;
use Magna\Settings\GeneralSettings;

/**
 * The document a freshly made header or footer starts from.
 *
 * Not empty, deliberately. Someone who asks to design a header wants to
 * move a logo and a menu around — handing them a blank document makes them
 * build the obvious part first, and the obvious part is the same every
 * time. Starting from something real also shows what a header IS in this
 * builder: ordinary sections, columns and blocks, editable like any other.
 *
 * Every block here is a CORE block, so a starter never depends on a plugin
 * being installed. The nav block belongs to this plugin, which ships the
 * builder, so it is always present when this code can run.
 */
final class ChromeStarter
{
    private static function siteName(): string
    {
        $name = GeneralSettings::get()->site_name;

        return is_string($name) && $name !== '' ? $name : 'Your site';
    }

    /** The same id shape the patch path mints, so nothing is special. */
    private static function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function document(string $role): array
    {
        return $role === 'footer' ? self::footer() : self::header();
    }

    /**
     * Logo on the left, menu on the right — the arrangement nearly every
     * header starts as, in two columns so moving either is a drag.
     *
     * @return list<array<string, mixed>>
     */
    private static function header(): array
    {
        return [[
            'id' => self::id(),
            'type' => 'section',
            'settings' => ['label' => 'Header', 'style' => ['paddingTop' => '16px', 'paddingBottom' => '16px']],
            'columns' => [
                [
                    'id' => self::id(),
                    'span' => 4,
                    'settings' => ['style' => ['alignItems' => 'center']],
                    'blocks' => [[
                        'id' => self::id(),
                        'block' => 'heading',
                        'settings' => [],
                        // The site's name as LITERAL text, not a binding.
                        // A starter exists to be edited, and a bound value
                        // cannot be typed over on the canvas — an editor
                        // replacing this with their own wordmark should not
                        // have to learn about bindings to do it.
                        'data' => ['text' => self::siteName(), 'level' => 'h1', 'align' => 'left'],
                    ]],
                ],
                [
                    'id' => self::id(),
                    'span' => 8,
                    'settings' => ['style' => ['alignItems' => 'center', 'textAlign' => 'right']],
                    'blocks' => [[
                        'id' => self::id(),
                        'block' => 'nav',
                        'settings' => [],
                        'data' => ['menu' => 'primary'],
                    ]],
                ],
            ],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function footer(): array
    {
        return [[
            'id' => self::id(),
            'type' => 'section',
            'settings' => ['label' => 'Footer', 'style' => ['paddingTop' => '32px', 'paddingBottom' => '32px']],
            'columns' => [[
                'id' => self::id(),
                'span' => 12,
                'settings' => ['style' => ['textAlign' => 'center']],
                'blocks' => [[
                    'id' => self::id(),
                    'block' => 'text',
                    'settings' => [],
                    // site.year IS a dynamic tag, so this one stays live:
                    // a copyright year that needs editing every January is
                    // a copyright year that will be wrong every January.
                    'data' => ['body' => '<p>© {tag:site.year} '.e(self::siteName()).'</p>'],
                ]],
            ]],
        ]];
    }
}
