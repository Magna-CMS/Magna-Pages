<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Magna\Content\Entry;
use Magna\Content\FieldTypes\BlocksField;
use Magna\Content\SchemaRegistry;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;

/**
 * The site and a site kit, in one comparison form — what `magna:site:diff`
 * and three-way theme updates (ThemeKitUpdater) compare.
 *
 * The database is not a byte store, and every difference it introduces on
 * its own has to be taken out before "the site differs from the kit" can
 * mean "somebody edited it":
 *
 *   • blocks_data is jsonb, which hands objects back in its own key order —
 *     so keys are sorted at every depth, not only the top;
 *   • rich-text bodies are sanitised on save — so the kit side goes through
 *     the same at-rest sanitising (BlocksField::prepareForStorage);
 *   • the site holds fields the kit never sets (a path derived from the
 *     slug, a meta description an editor added) — so only the fields a kit
 *     row carries are compared, which are the only ones applying it writes;
 *   • a menu tree comes back in syncItems() shape, without the kit's ids and
 *     empty children — so both sides are reduced to that shape.
 *
 * Before this, lekha/billing's live site reported 16 of 16 pages changed
 * while matching the kit word for word.
 */
final class KitComparer
{
    /*
     * `page_settings` travels with the page since the Lekha FAQ incident:
     * a stale per-page background rode a database row that no export, diff,
     * or admin screen ever showed, and the only way to find it was to query
     * the table by hand. A kit that drops the key would recreate exactly
     * that invisible drift between environments.
     *
     * Its sync semantics are deliberate and asymmetric:
     *  - absent from the kit row  → left alone on apply (EntryManager
     *    updates are partial), so a kit exported before this key existed
     *    cannot null out settings a page already has;
     *  - present and empty (`[]`) → an explicit clear, and it applies;
     *  - for DIFF, empty and absent compare equal (see comparable()),
     *    because a page whose override was cleared and a page that never
     *    had one are the same site.
     */
    public const ENTRY_KEYS = ['title', 'slug', 'path', 'locale', 'translation_group', 'template', 'kind', 'meta_title', 'meta_description', 'blocks_data', 'page_settings'];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly MenuManager $menus,
    ) {}

    /** JSON with object keys sorted at every depth; list order is meaning and stays. */
    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> an entry as an export row: status plus every set field */
    public static function entryRow(Entry $entry): array
    {
        return [
            'status' => $entry->status->value,
            ...array_filter(
                array_combine(self::ENTRY_KEYS, array_map($entry->getAttribute(...), self::ENTRY_KEYS)),
                fn ($v): bool => $v !== null,
            ),
        ];
    }

    /**
     * A site row compared against one kit row: only the fields that row sets.
     *
     * @param  array<string, mixed>  $localRow
     * @param  array<string, mixed>  $kitRow
     */
    public static function compareTo(array $localRow, array $kitRow): string
    {
        return self::comparable(array_intersect_key($localRow, self::kitFields($kitRow)));
    }

    /**
     * A kit row in comparison form, as the site would store it.
     *
     * @param  array<string, mixed>  $row
     */
    public static function entryStateOf(array $row): string
    {
        return self::comparable(self::kitFields($row));
    }

    /**
     * The site's own version of an entry against one kit row — null when the
     * site has none.
     *
     * @param  array<string, mixed>  $kitRow
     */
    public function localEntryState(string $type, string $slug, string $locale, array $kitRow): ?string
    {
        if (! $this->schemas->has($type)) {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entry::type($type)->where('slug', $slug)->where('locale', $locale)->first();

        return $entry === null ? null : self::compareTo(self::entryRow($entry), $kitRow);
    }

    /** The site's menu tree in comparison form, or null when it has no such menu. */
    public function localMenuState(string $handle): ?string
    {
        $menu = Menu::query()->where('handle', $handle)->first();

        return $menu === null ? null : self::canonical(self::menuShape($this->menus->exportTree($menu)));
    }

    /**
     * A kit's menu tree in the same form.
     *
     * @param  list<mixed>  $tree
     */
    public static function menuStateOf(array $tree): string
    {
        return self::canonical(self::menuShape($tree));
    }

    /** The site's saved pattern in comparison form, or null when it has none. */
    public function localPatternState(string $name): ?string
    {
        $pattern = Pattern::query()->where('name', trim($name))->first();

        return $pattern === null ? null : self::canonical(['kind' => (string) $pattern->kind, 'document' => $pattern->document]);
    }

    /**
     * A kit pattern row in the same form.
     *
     * @param  array<string, mixed>  $row
     */
    public static function patternStateOf(array $row): string
    {
        return self::canonical(['kind' => (string) ($row['kind'] ?? ''), 'document' => $row['document'] ?? null]);
    }

    /**
     * Normalised form of an entry row: translation_group excluded (an env
     * fact), and a cleared page-settings override equal to none — without
     * that, a page whose override was cleared after an export would read as
     * "changed" forever, because apply leaves an absent key alone and so can
     * never make them equal.
     *
     * @param  array<string, mixed>  $row
     */
    private static function comparable(array $row): string
    {
        unset($row['translation_group']);

        if (($row['page_settings'] ?? null) === []) {
            unset($row['page_settings']);
        }

        return self::canonical($row);
    }

    /**
     * The fields of a kit row that applying it writes, as storage holds them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function kitFields(array $row): array
    {
        $fields = array_intersect_key($row, array_flip(['status', ...self::ENTRY_KEYS]));

        if (isset($fields['blocks_data'])) {
            $fields['blocks_data'] = (new BlocksField)->prepareForStorage($fields['blocks_data']);
        }

        return $fields;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * A menu tree reduced to what syncItems() stores.
     *
     * @param  array<mixed>  $tree
     * @return list<array<string, mixed>>
     */
    private static function menuShape(array $tree): array
    {
        $shaped = [];

        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }

            $settings = array_filter(
                is_array($node['settings'] ?? null) ? $node['settings'] : [],
                static fn (mixed $value): bool => $value !== '' && $value !== null,
            );

            $shaped[] = array_filter([
                'label' => is_string($node['label'] ?? null) ? $node['label'] : '',
                'type' => in_array($node['type'] ?? null, ['page', 'plugin'], true) ? $node['type'] : 'url',
                'url' => is_string($node['url'] ?? null) ? $node['url'] : null,
                'target' => is_string($node['target'] ?? null) ? $node['target'] : null,
                'settings' => $settings === [] ? null : $settings,
                'page_slug' => is_string($node['page_slug'] ?? null) ? $node['page_slug'] : null,
                'children' => is_array($node['children'] ?? null) && $node['children'] !== [] ? self::menuShape($node['children']) : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $shaped;
    }
}
