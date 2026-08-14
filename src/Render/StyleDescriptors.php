<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

/**
 * The style vocabulary: which keys `settings.style` may carry, what CSS
 * each one emits, and what control the builder should draw for it.
 *
 * ONE table, owned by the server and shipped to the builder in the
 * bootstrap payload — the same trick block fields already use to drive the
 * inspector. A mirrored table in TypeScript would be a second source of
 * truth, and the day they disagreed an editor would set a value the
 * renderer had never heard of and watch nothing happen.
 *
 * Keys are an ALLOWLIST. A `settings.style` entry this table does not
 * name emits nothing, so a document written by a newer version (or by
 * hand) degrades to today's rendering instead of becoming a way to write
 * arbitrary CSS properties.
 *
 * Sections and columns are elements this plugin's own partial renders, so
 * their declarations land in a style attribute on markup we control. A
 * BLOCK renders its own markup, so its declarations go to the per-node
 * stylesheet instead and reach it through a class merged into whatever
 * element the block already rendered (see BlockStyleMarkup) — adding a
 * second `class` or `style` attribute would silently beat the block's own,
 * because the parser keeps the first and discards the rest.
 */
final class StyleDescriptors
{
    public const SECTION = 'section';

    public const COLUMN = 'column';

    public const BLOCK = 'block';

    /**
     * The ROW a section's columns lay out in — `.magna-columns`, not the
     * section itself. Its own kind because `alignItems` on a row means
     * "line the columns up" while on a column it means "line the contents
     * up", and one key that means two things is a key nobody can label.
     */
    public const ROW = 'row';

    /**
     * key => [property, control, group, label, options, appliesTo].
     *
     * `control` names what the builder draws: text (a length or keyword),
     * color, or select (options are the whole vocabulary for that key).
     *
     * @var array<string, array{property: string, control: string, group: string, label: string, options: list<string>, appliesTo: list<string>}>
     */
    private const KEYS = [
        'paddingTop' => ['property' => 'padding-top', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding top', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'paddingRight' => ['property' => 'padding-right', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding right', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'paddingBottom' => ['property' => 'padding-bottom', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding bottom', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'paddingLeft' => ['property' => 'padding-left', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding left', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'marginTop' => ['property' => 'margin-top', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Margin top', 'options' => [], 'appliesTo' => [self::SECTION, self::BLOCK]],
        'marginBottom' => ['property' => 'margin-bottom', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Margin bottom', 'options' => [], 'appliesTo' => [self::SECTION, self::BLOCK]],

        'background' => ['property' => 'background-color', 'control' => 'color', 'group' => 'Background', 'label' => 'Background', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'color' => ['property' => 'color', 'control' => 'color', 'group' => 'Typography', 'label' => 'Text colour', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'textAlign' => ['property' => 'text-align', 'control' => 'select', 'group' => 'Typography', 'label' => 'Text align', 'options' => ['', 'left', 'center', 'right'], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],

        'borderWidth' => ['property' => 'border-width', 'control' => 'text', 'group' => 'Border', 'label' => 'Border width', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'borderStyle' => ['property' => 'border-style', 'control' => 'select', 'group' => 'Border', 'label' => 'Border style', 'options' => ['', 'none', 'solid', 'dashed', 'dotted'], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'borderColor' => ['property' => 'border-color', 'control' => 'color', 'group' => 'Border', 'label' => 'Border colour', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],
        'borderRadius' => ['property' => 'border-radius', 'control' => 'text', 'group' => 'Border', 'label' => 'Corner radius', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK]],

        'fontSize' => ['property' => 'font-size', 'control' => 'text', 'group' => 'Typography', 'label' => 'Font size', 'options' => [], 'appliesTo' => [self::BLOCK]],
        'fontWeight' => ['property' => 'font-weight', 'control' => 'select', 'group' => 'Typography', 'label' => 'Weight', 'options' => ['', '300', '400', '500', '600', '700'], 'appliesTo' => [self::BLOCK]],
        'lineHeight' => ['property' => 'line-height', 'control' => 'text', 'group' => 'Typography', 'label' => 'Line height', 'options' => [], 'appliesTo' => [self::BLOCK]],
        'letterSpacing' => ['property' => 'letter-spacing', 'control' => 'text', 'group' => 'Typography', 'label' => 'Letter spacing', 'options' => [], 'appliesTo' => [self::BLOCK]],
        'maxWidth' => ['property' => 'max-width', 'control' => 'text', 'group' => 'Layout', 'label' => 'Maximum width', 'options' => [], 'appliesTo' => [self::BLOCK]],

        'gap' => ['property' => 'gap', 'control' => 'text', 'group' => 'Row layout', 'label' => 'Gap between columns', 'options' => [], 'appliesTo' => [self::ROW]],
        'rowAlign' => ['property' => 'align-items', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Align columns', 'options' => ['', 'flex-start', 'center', 'flex-end', 'stretch'], 'appliesTo' => [self::ROW]],
        'rowJustify' => ['property' => 'justify-content', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Distribute columns', 'options' => ['', 'flex-start', 'center', 'flex-end', 'space-between', 'space-around'], 'appliesTo' => [self::ROW]],
        'rowDirection' => ['property' => 'flex-direction', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Direction', 'options' => ['', 'row', 'row-reverse', 'column'], 'appliesTo' => [self::ROW]],
        'rowWrap' => ['property' => 'flex-wrap', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Wrapping', 'options' => ['', 'wrap', 'nowrap'], 'appliesTo' => [self::ROW]],

        'minHeight' => ['property' => 'min-height', 'control' => 'text', 'group' => 'Layout', 'label' => 'Minimum height', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN]],
        'justifyContent' => ['property' => 'justify-content', 'control' => 'select', 'group' => 'Layout', 'label' => 'Horizontal align', 'options' => ['', 'flex-start', 'center', 'flex-end', 'space-between'], 'appliesTo' => [self::COLUMN]],
        'alignItems' => ['property' => 'align-items', 'control' => 'select', 'group' => 'Layout', 'label' => 'Vertical align', 'options' => ['', 'flex-start', 'center', 'flex-end', 'stretch'], 'appliesTo' => [self::COLUMN]],
    ];

    /**
     * The controls the builder should draw for a node of this kind.
     *
     * @return list<array<string, mixed>>
     */
    public static function forKind(string $kind): array
    {
        $controls = [];

        foreach (self::KEYS as $key => $descriptor) {
            if (! in_array($kind, $descriptor['appliesTo'], true)) {
                continue;
            }

            $controls[] = [
                'key' => $key,
                'label' => $descriptor['label'],
                'control' => $descriptor['control'],
                'group' => $descriptor['group'],
                'options' => $descriptor['options'],
            ];
        }

        return $controls;
    }

    /**
     * The whole table, keyed by node kind, for the bootstrap payload.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function forBuilder(): array
    {
        return [
            self::SECTION => self::forKind(self::SECTION),
            self::COLUMN => self::forKind(self::COLUMN),
            self::BLOCK => self::forKind(self::BLOCK),
            self::ROW => self::forKind(self::ROW),
        ];
    }

    /** Whether this style set positions the node's own contents. */
    private static function alignsContents(mixed $style): bool
    {
        if (! is_array($style)) {
            return false;
        }

        foreach (['justifyContent', 'alignItems'] as $key) {
            $value = $style[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The CSS declarations a node's `settings.style` produces, sanitized.
     *
     * Values go through CustomCss, which is the one place this plugin
     * decides what may appear in a declaration — a second validator would
     * be a second thing to keep correct, and this one already refuses
     * functions other than var(--token).
     */
    public static function declarations(mixed $style, string $kind, string $breakpoint = 'base'): string
    {
        if (! is_array($style) || $style === []) {
            return '';
        }

        $parts = [];
        foreach (self::KEYS as $key => $descriptor) {
            if (! in_array($kind, $descriptor['appliesTo'], true)) {
                continue;
            }

            // A value may be a scalar or a per-device set; `base` reads a
            // scalar unchanged, so nothing about a plain document changes.
            $value = ResponsiveStyles::valueAt($style[$key] ?? null, $breakpoint);
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            // A select may only emit what it offers. The options ARE the
            // vocabulary, not a suggestion the renderer trusts.
            if ($descriptor['control'] === 'select' && ! in_array($value, $descriptor['options'], true)) {
                continue;
            }

            $parts[] = $descriptor['property'].':'.$value;
        }

        // A column is a flex ITEM of the row, not a flex container, so
        // aligning its contents does nothing until it becomes one. Derived
        // rather than offered as a control: "why did my align setting need
        // a display setting too" is a question no editor should be asked.
        if ($parts !== [] && $kind === self::COLUMN && self::alignsContents($style)) {
            array_unshift($parts, 'display:flex', 'flex-direction:column');
        }

        return CustomCss::sanitize(implode(';', $parts));
    }
}
