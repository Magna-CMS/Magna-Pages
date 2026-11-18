<?php

declare(strict_types=1);

namespace Magna\Pages\SiteKit;

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Contracts\RemovesSiteKitRows;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuItem;
use Magna\Pages\PagesSettings;
use Magna\Settings\SettingsRepository;
use Magna\Themes\ThemeContentRemover;

/**
 * The page builder's answer to core's "take this theme's demo content back
 * out": every row of the bundle the site still has exactly as shipped goes,
 * everything else stays and says why.
 *
 * "Exactly as shipped" is the comparison the theme update already trusts
 * (KitComparer, and the section rows' own shape), so an edited page, an
 * admin's own page under a demo slug, a menu with one item moved, all read
 * as changed and are kept. An untouched page can still be needed: a menu
 * that stays links to it, a page that stays links to its path or embeds it
 * by id, it has pages under it, a collection is mounted on it. Those stay
 * too, and keeping one can make another needed, so the check runs until
 * nothing more moves.
 *
 * Bound by PagesPlugin only when core defines the contract — this file is
 * never loaded on a core that predates it.
 */
final class ThemeKitRemover implements ThemeContentRemover
{
    use ReadsKitRows;

    private const ENTRY_KINDS = ['page' => 'pages', 'pages_template' => 'templates'];

    /** Settings that point at an entry by id and fall back cleanly when cleared. */
    private const ENTRY_SETTINGS = ['home_page_id', 'not_found_page_id', 'default_header_id', 'default_footer_id'];

    private const EDITED = 'you changed it';

    public function __construct(
        private readonly SiteKit $kits,
        private readonly KitComparer $compare,
        private readonly EntryManager $entries,
        private readonly PageCache $cache,
        private readonly SettingsRepository $settings,
    ) {}

    public function preview(array $kit): array
    {
        $plan = $this->plan($kit);

        return ['remove' => $plan['remove'], 'keep' => $plan['keep']];
    }

    public function removeUntouched(array $kit, ?string $actorId = null): array
    {
        $plan = $this->plan($kit);
        $counts = ['pages' => 0, 'templates' => 0, 'menus' => 0, 'patterns' => 0, 'kept' => count($plan['keep'])];

        DB::transaction(function () use ($plan, $actorId, &$counts): void {
            foreach ($plan['sections'] as $key => [$section, $rows]) {
                $counts[$key] = $section->remove($rows, $actorId);
            }

            foreach ($plan['menus'] as $menu) {
                $menu->items()->delete();
                $menu->delete();
                $counts['menus']++;
            }

            foreach ($plan['patterns'] as $pattern) {
                $pattern->delete();
                $counts['patterns']++;
            }

            $this->releaseSettings(array_map(static fn (array $c): string => (string) $c['entry']->getKey(), $plan['entries']));

            foreach ($plan['entries'] as $candidate) {
                $this->entries->delete($candidate['entry'], $actorId);
                $counts[$candidate['kind']]++;
            }
        });

        // Entry deletes flush their own pages; menus and patterns show on all.
        $this->cache->flush();

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $kit
     * @return array{
     *     remove: list<array{key: string, label: string}>,
     *     keep: list<array{key: string, label: string, reason: string}>,
     *     entries: array<string, array{kind: string, entry: Entry, label: string}>,
     *     menus: list<Menu>,
     *     patterns: list<Pattern>,
     *     sections: array<string, array{0: RemovesSiteKitRows, 1: list<array<string, mixed>>}>
     * }
     */
    private function plan(array $kit): array
    {
        self::assertKit($kit);

        $remove = [];
        $keep = [];
        $candidates = [];
        // Every page, part and menu of this demo the site has, edited or not:
        // what links from these does not hold anything back (see holdNeeded).
        $demoEntryIds = [];
        $demoMenuIds = [];

        foreach (self::ENTRY_KINDS as $type => $kind) {
            foreach (self::rows($kit, $kind) as $row) {
                $id = self::entryIdentity($row);
                if ($id === null) {
                    continue;
                }

                [$slug, $locale] = explode('@', $id, 2);
                $state = $this->compare->localEntryState($type, $slug, $locale, $row);
                $entry = $state === null ? null : Entry::type($type)->where('slug', $slug)->where('locale', $locale)->first();
                if ($entry === null) {
                    continue;
                }

                $demoEntryIds[] = (string) $entry->getKey();
                $key = self::entryKey($type, $id);
                $label = ($type === 'page' ? 'page ' : 'header/footer part ').$slug.($locale !== '' ? ' ('.$locale.')' : '');

                if ($state !== KitComparer::entryStateOf($row)) {
                    $keep[$key] = ['key' => $key, 'label' => $label, 'reason' => self::EDITED];
                } else {
                    $candidates[$key] = ['kind' => $kind, 'entry' => $entry, 'label' => $label];
                }
            }
        }

        $menus = [];
        foreach (self::rows($kit, 'menus') as $row) {
            $handle = self::handleOf($row);
            $state = $handle === null || ! is_array($row['tree'] ?? null) ? null : $this->compare->localMenuState($handle);
            $menu = $state === null ? null : Menu::query()->where('handle', $handle)->first();
            if ($handle === null || $menu === null) {
                continue;
            }

            $demoMenuIds[] = (string) $menu->getKey();
            $label = 'menu '.(is_string($row['name'] ?? null) ? $row['name'] : $handle);
            if ($state !== KitComparer::menuStateOf($row['tree'])) {
                $keep['menu:'.$handle] = ['key' => 'menu:'.$handle, 'label' => $label, 'reason' => self::EDITED];
            } else {
                $menus[] = $menu;
                $remove['menu:'.$handle] = ['key' => 'menu:'.$handle, 'label' => $label];
            }
        }

        $patterns = [];
        foreach (self::rows($kit, 'patterns') as $row) {
            $name = self::nameOf($row);
            $state = $name === null ? null : $this->compare->localPatternState($name);
            if ($name === null || $state === null) {
                continue;
            }

            if ($state !== KitComparer::patternStateOf($row)) {
                $keep['pattern:'.$name] = ['key' => 'pattern:'.$name, 'label' => 'pattern '.$name, 'reason' => self::EDITED];
            } elseif (($pattern = Pattern::query()->where('name', $name)->first()) !== null) {
                $patterns[] = $pattern;
                $remove['pattern:'.$name] = ['key' => 'pattern:'.$name, 'label' => 'pattern '.$name];
            }
        }

        $sections = $this->planSections($kit, $remove, $keep);

        $candidates = $this->holdNeeded($candidates, $demoEntryIds, $demoMenuIds, $keep);
        foreach ($candidates as $key => $candidate) {
            $remove[$key] = ['key' => $key, 'label' => $candidate['label']];
        }

        return [
            'remove' => array_values($remove),
            'keep' => array_values($keep),
            'entries' => $candidates,
            'menus' => $menus,
            'patterns' => $patterns,
            'sections' => $sections,
        ];
    }

    /**
     * Plugin sections: a row is untouched when the site's export of it,
     * cut to the fields the kit row carries, is the kit row.
     *
     * @param  array<string, mixed>  $kit
     * @param  array<string, array{key: string, label: string}>  $remove
     * @param  array<string, array{key: string, label: string, reason: string}>  $keep
     * @return array<string, array{0: RemovesSiteKitRows, 1: list<array<string, mixed>>}>
     */
    private function planSections(array $kit, array &$remove, array &$keep): array
    {
        $planned = [];

        foreach ($this->kits->sections() as $sectionKey => $section) {
            if (! is_array($kit[$sectionKey] ?? null)) {
                continue;
            }

            $local = self::byIdentity($section->export(), self::rowIdentity(...));
            $noun = match ($sectionKey) {
                'blog:posts' => 'post',
                'forms:forms' => 'form',
                default => $sectionKey,
            };

            foreach (self::rows($kit, $sectionKey) as $row) {
                $id = self::rowIdentity($row);
                if ($id === null || ! isset($local[$id])) {
                    continue;
                }

                $key = $sectionKey.'/'.$id;
                $label = $noun.' '.$id;
                $reason = match (true) {
                    KitComparer::canonical(self::rowShape($local[$id], $row)) !== KitComparer::canonical($row) => self::EDITED,
                    ! $section instanceof RemovesSiteKitRows => 'its plugin cannot remove it yet',
                    default => $section->keep($row),
                };

                if ($reason !== null) {
                    $keep[$key] = ['key' => $key, 'label' => $label, 'reason' => $reason];

                    continue;
                }

                /** @var RemovesSiteKitRows $section */
                $planned[$sectionKey] ??= [$section, []];
                $planned[$sectionKey][1][] = $row;
                $remove[$key] = ['key' => $key, 'label' => $label];
            }
        }

        return $planned;
    }

    /**
     * Drop from the candidates every page or part the site's OWN content
     * still needs: a page or menu the owner made, a page under it, a
     * collection mounted on it.
     *
     * Only the owner's own content counts. The demo's pages link to each
     * other, so if a demo page the owner edited (and so keeps) could hold the
     * pages it links to, one edited FAQ would hold the whole demo in place —
     * which is what the first real run against a theme's demo did. A link
     * from kept demo content to removed demo content may break; the owner
     * sees that page listed as kept, and a link from their own pages never
     * breaks.
     *
     * @param  array<string, array{kind: string, entry: Entry, label: string}>  $candidates
     * @param  list<string>  $demoEntryIds  every entry of this demo on the site, edited or not
     * @param  list<string>  $demoMenuIds  every menu of this demo on the site, edited or not
     * @param  array<string, array{key: string, label: string, reason: string}>  $keep
     * @return array<string, array{kind: string, entry: Entry, label: string}>
     */
    private function holdNeeded(array $candidates, array $demoEntryIds, array $demoMenuIds, array &$keep): array
    {
        $mounts = json_encode(PagesSettings::get()->collection_mounts) ?: '';
        $ownDocuments = $this->documentsOutside($demoEntryIds);

        foreach ($candidates as $key => $candidate) {
            $reason = $this->neededBecause($candidate['entry'], $demoEntryIds, $demoMenuIds, $ownDocuments, $mounts);

            if ($reason !== null) {
                $keep[$key] = ['key' => $key, 'label' => $candidate['label'], 'reason' => $reason];
                unset($candidates[$key]);
            }
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $demoEntryIds
     * @param  list<string>  $demoMenuIds
     * @param  list<array{slug: string, text: string}>  $ownDocuments
     */
    private function neededBecause(Entry $entry, array $demoEntryIds, array $demoMenuIds, array $ownDocuments, string $mounts): ?string
    {
        $id = (string) $entry->getKey();

        $menuId = MenuItem::query()->where('type', 'page')->where('page_id', $id)
            ->whereNotIn('menu_id', $demoMenuIds)->value('menu_id');
        if ($menuId !== null) {
            return 'linked from the '.(Menu::query()->whereKey($menuId)->value('name') ?? 'a').' menu';
        }

        if ($mounts !== '' && str_contains($mounts, $id)) {
            return 'a collection is mounted on it';
        }

        $path = (string) ($entry->getAttribute('path') ?? $entry->getAttribute('slug') ?? '');
        // "/about" as a whole link target, wherever it sits: a block's href
        // field, or an <a href=\"/about#team\"> inside rich text. Not
        // "/about-us", "/team/about" or another site's "https://x/about".
        $link = $entry->getHandle() === 'page' && trim($path, '/') !== ''
            ? '#(?<![\w/.])/'.preg_quote(trim($path, '/'), '#').'/?(?=["\'\\\\?\#\s<]|$)#'
            : null;

        foreach ($ownDocuments as $document) {
            if (str_contains($document['text'], $id)) {
                return 'used by '.$document['slug'];
            }
            if ($link !== null && preg_match($link, $document['text']) === 1) {
                return 'linked from '.$document['slug'];
            }
        }

        if ($entry->getHandle() === 'page' && Entry::type('page')->where('parent_id', $id)->whereNotIn('id', $demoEntryIds)->exists()) {
            return 'has pages under it';
        }

        return null;
    }

    /**
     * The raw documents of every page and part that is not this demo's,
     * slashes unescaped so a link reads the same however the JSON was written.
     *
     * @param  list<string>  $demoEntryIds
     * @return list<array{slug: string, text: string}>
     */
    private function documentsOutside(array $demoEntryIds): array
    {
        $documents = [];

        foreach (array_keys(self::ENTRY_KINDS) as $type) {
            $table = Entry::type($type)->getModel()->getTable();

            foreach (DB::table($table)->whereNotIn('id', $demoEntryIds)->get(['slug', 'blocks_data', 'page_settings']) as $row) {
                $documents[] = [
                    'slug' => (string) $row->slug,
                    'text' => str_replace('\\/', '/', $row->blocks_data.' '.$row->page_settings),
                ];
            }
        }

        return $documents;
    }

    /**
     * The home, not-found, header and footer settings fall back to their
     * defaults rather than point at a page that is gone.
     *
     * @param  list<string>  $ids
     */
    private function releaseSettings(array $ids): void
    {
        $settings = PagesSettings::get();
        $changed = false;

        foreach (self::ENTRY_SETTINGS as $field) {
            if (is_string($settings->{$field}) && in_array($settings->{$field}, $ids, true)) {
                $settings->{$field} = null;
                $changed = true;
            }
        }

        if ($changed) {
            $this->settings->persist($settings);
        }
    }
}
