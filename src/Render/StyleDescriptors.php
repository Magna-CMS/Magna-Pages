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
     * A CONTAINER block — everything a block may style, plus the flex
     * controls that make holding other blocks worth anything.
     *
     * Its own kind rather than more keys on BLOCK: a heading offered a
     * `gap` would be offered a control that does nothing, and the
     * vocabulary a node is shown is the promise that setting it will
     * matter. Unlike ROW, these land on the container's OWN element, so
     * they ride `settings.style` like every other block declaration
     * rather than needing a second settings key.
     */
    public const CONTAINER = 'container';

    /**
     * The PAGE itself — the ground everything else is drawn on.
     *
     * Its own kind because the vocabulary genuinely differs: a background
     * image belongs to a page and a `gap` does not, and offering a control
     * that cannot matter is the one thing this table exists to prevent.
     *
     * Page declarations land on `body`, emitted with the document's own
     * stylesheet rather than in the head, so a theme cannot forget to
     * print them — the same reason the structural utilities live there.
     */
    public const PAGE = 'page';

    /**
     * key => [property, control, group, label, options, appliesTo].
     *
     * `control` names what the builder draws: text (a length or keyword),
     * color, or select (options are the whole vocabulary for that key).
     *
     * @var array<string, array{property: string, control: string, group: string, label: string, options: list<string>, appliesTo: list<string>}>
     */
    private const KEYS = [
        'paddingTop' => ['property' => 'padding-top', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding top', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'paddingRight' => ['property' => 'padding-right', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding right', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'paddingBottom' => ['property' => 'padding-bottom', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding bottom', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'paddingLeft' => ['property' => 'padding-left', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Padding left', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'marginTop' => ['property' => 'margin-top', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Margin top', 'options' => [], 'appliesTo' => [self::SECTION, self::BLOCK, self::CONTAINER]],
        'marginBottom' => ['property' => 'margin-bottom', 'control' => 'text', 'group' => 'Spacing', 'label' => 'Margin bottom', 'options' => [], 'appliesTo' => [self::SECTION, self::BLOCK, self::CONTAINER]],

        'background' => ['property' => 'background-color', 'control' => 'color', 'group' => 'Background', 'label' => 'Background', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'color' => ['property' => 'color', 'control' => 'color', 'group' => 'Typography', 'label' => 'Text colour', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER, self::PAGE]],
        'textAlign' => ['property' => 'text-align', 'control' => 'select', 'group' => 'Typography', 'label' => 'Text align', 'options' => ['', 'left', 'center', 'right'], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER]],

        'borderWidth' => ['property' => 'border-width', 'control' => 'text', 'group' => 'Border', 'label' => 'Border width', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER]],
        'borderStyle' => ['property' => 'border-style', 'control' => 'select', 'group' => 'Border', 'label' => 'Border style', 'options' => ['', 'none', 'solid', 'dashed', 'dotted'], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER]],
        'borderColor' => ['property' => 'border-color', 'control' => 'color', 'group' => 'Border', 'label' => 'Border colour', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER]],
        'borderRadius' => ['property' => 'border-radius', 'control' => 'text', 'group' => 'Border', 'label' => 'Corner radius', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::BLOCK, self::CONTAINER]],

        'fontSize' => ['property' => 'font-size', 'control' => 'text', 'group' => 'Typography', 'label' => 'Font size', 'options' => [], 'appliesTo' => [self::BLOCK, self::CONTAINER]],
        'fontWeight' => ['property' => 'font-weight', 'control' => 'select', 'group' => 'Typography', 'label' => 'Weight', 'options' => ['', '300', '400', '500', '600', '700'], 'appliesTo' => [self::BLOCK, self::CONTAINER]],
        'lineHeight' => ['property' => 'line-height', 'control' => 'text', 'group' => 'Typography', 'label' => 'Line height', 'options' => [], 'appliesTo' => [self::BLOCK, self::CONTAINER]],
        'letterSpacing' => ['property' => 'letter-spacing', 'control' => 'text', 'group' => 'Typography', 'label' => 'Letter spacing', 'options' => [], 'appliesTo' => [self::BLOCK, self::CONTAINER]],
        'maxWidth' => ['property' => 'max-width', 'control' => 'text', 'group' => 'Layout', 'label' => 'Maximum width', 'options' => [], 'appliesTo' => [self::BLOCK, self::CONTAINER]],

        'gap' => ['property' => 'gap', 'control' => 'text', 'group' => 'Row layout', 'label' => 'Gap between columns', 'options' => [], 'appliesTo' => [self::ROW]],
        'rowAlign' => ['property' => 'align-items', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Align columns', 'options' => ['', 'flex-start', 'center', 'flex-end', 'stretch'], 'appliesTo' => [self::ROW]],
        'rowJustify' => ['property' => 'justify-content', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Distribute columns', 'options' => ['', 'flex-start', 'center', 'flex-end', 'space-between', 'space-around'], 'appliesTo' => [self::ROW]],
        'rowDirection' => ['property' => 'flex-direction', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Direction', 'options' => ['', 'row', 'row-reverse', 'column'], 'appliesTo' => [self::ROW]],
        'rowWrap' => ['property' => 'flex-wrap', 'control' => 'select', 'group' => 'Row layout', 'label' => 'Wrapping', 'options' => ['', 'wrap', 'nowrap'], 'appliesTo' => [self::ROW]],

        'minHeight' => ['property' => 'min-height', 'control' => 'text', 'group' => 'Layout', 'label' => 'Minimum height', 'options' => [], 'appliesTo' => [self::SECTION, self::COLUMN, self::CONTAINER]],
        'justifyContent' => ['property' => 'justify-content', 'control' => 'select', 'group' => 'Layout', 'label' => 'Horizontal align', 'options' => ['', 'flex-start', 'center', 'flex-end', 'space-between'], 'appliesTo' => [self::COLUMN, self::CONTAINER]],
        'alignItems' => ['property' => 'align-items', 'control' => 'select', 'group' => 'Layout', 'label' => 'Vertical align', 'options' => ['', 'flex-start', 'center', 'flex-end', 'stretch'], 'appliesTo' => [self::COLUMN, self::CONTAINER]],

        // What makes a container a layout and not just a box. Named for
        // what they do to the blocks INSIDE it, which is the only thing an
        // editor is deciding when they reach for them.
        'direction' => ['property' => 'flex-direction', 'control' => 'select', 'group' => 'Layout', 'label' => 'Stack direction', 'options' => ['', 'row', 'row-reverse', 'column', 'column-reverse'], 'appliesTo' => [self::CONTAINER]],
        'wrap' => ['property' => 'flex-wrap', 'control' => 'select', 'group' => 'Layout', 'label' => 'Wrapping', 'options' => ['', 'wrap', 'nowrap'], 'appliesTo' => [self::CONTAINER]],
        'childGap' => ['property' => 'gap', 'control' => 'text', 'group' => 'Layout', 'label' => 'Gap between blocks', 'options' => [], 'appliesTo' => [self::CONTAINER]],

        /*
         * The page's background image.
         *
         * `image` is its own control because the stored value is a URL and
         * the emitted value is a `url()` function. The author's string
         * never reaches the property: the emitter validates the URL and
         * builds the function itself, which is the only reason a value
         * carrying parentheses can be allowed near a stylesheet at all.
         */
        'backgroundImage' => ['property' => 'background-image', 'control' => 'image', 'group' => 'Background', 'label' => 'Background image', 'options' => [], 'appliesTo' => [self::PAGE]],
        'backgroundSize' => ['property' => 'background-size', 'control' => 'select', 'group' => 'Background', 'label' => 'Image size', 'options' => ['', 'cover', 'contain', 'auto'], 'appliesTo' => [self::PAGE]],
        'backgroundPosition' => ['property' => 'background-position', 'control' => 'select', 'group' => 'Background', 'label' => 'Image position', 'options' => ['', 'center', 'top', 'bottom', 'left', 'right'], 'appliesTo' => [self::PAGE]],
        'backgroundRepeat' => ['property' => 'background-repeat', 'control' => 'select', 'group' => 'Background', 'label' => 'Repeat', 'options' => ['', 'no-repeat', 'repeat', 'repeat-x', 'repeat-y'], 'appliesTo' => [self::PAGE]],
        'backgroundAttachment' => ['property' => 'background-attachment', 'control' => 'select', 'group' => 'Background', 'label' => 'Scrolling', 'options' => ['', 'scroll', 'fixed'], 'appliesTo' => [self::PAGE]],
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
            self::CONTAINER => self::forKind(self::CONTAINER),
            self::PAGE => self::forKind(self::PAGE),
        ];
    }

    /**
     * A `url("…")` for a stored image reference, or null.
     *
     * Relative paths and http(s) only — a `data:` or `javascript:` value
     * has no business being a page background, and anything carrying a
     * quote, a backslash, a paren or whitespace is refused outright rather
     * than escaped. Refusing is safe here in a way escaping is not: the
     * only thing lost is a background nobody could have meant.
     */
    private static function imageUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        if (preg_match('/[\s"\'\\\\()<>;{}]/', $value) === 1) {
            return null;
        }

        $isRelative = str_starts_with($value, '/') && ! str_starts_with($value, '//');
        if (! $isRelative) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
        }

        return 'url("'.$value.'")';
    }

    /** Whether a style set says anything under this key at all. */
    private static function declares(mixed $style, string $key): bool
    {
        if (! is_array($style)) {
            return false;
        }

        $value = ResponsiveStyles::valueAt($style[$key] ?? null, 'base');

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Whether this style set positions the node's own contents.
     *
     * @param  list<string>  $keys  The ones that mean "arrange what is inside
     *                              me" for this kind — a column has two, a
     *                              container has the whole flex vocabulary.
     */
    private static function alignsContents(mixed $style, array $keys): bool
    {
        if (! is_array($style)) {
            return false;
        }

        foreach ($keys as $key) {
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

            /*
             * An image value is a URL, and the property wants a `url()`
             * function around it. The author's string is never what gets
             * emitted: it is validated as a URL first and the function is
             * built here, which is why a value carrying parentheses can be
             * allowed anywhere near a stylesheet.
             */
            if ($descriptor['control'] === 'image') {
                $url = self::imageUrl($value);
                if ($url === null) {
                    continue;
                }

                $parts[] = $descriptor['property'].':'.$url;

                continue;
            }

            $parts[] = $descriptor['property'].':'.$value;
        }

        // A column is a flex ITEM of the row, not a flex container, so
        // aligning its contents does nothing until it becomes one. Derived
        // rather than offered as a control: "why did my align setting need
        // a display setting too" is a question no editor should be asked.
        if ($parts !== [] && $kind === self::COLUMN
            && self::alignsContents($style, ['justifyContent', 'alignItems'])
        ) {
            array_unshift($parts, 'display:flex', 'flex-direction:column');
        }

        // A container is a plain block until something inside it needs
        // arranging, for the same reason and with the same answer. It
        // stacks by default, so a container that only sets a gap behaves
        // the way the editor expects without naming a direction.
        if ($parts !== [] && $kind === self::CONTAINER
            && self::alignsContents($style, ['justifyContent', 'alignItems', 'direction', 'wrap', 'childGap'])
        ) {
            array_unshift($parts, 'display:flex');

            if (! self::declares($style, 'direction')) {
                array_unshift($parts, 'flex-direction:column');
            }
        }

        return CustomCss::sanitize(implode(';', $parts));
    }
}
