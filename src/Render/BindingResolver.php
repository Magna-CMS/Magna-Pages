<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Content\Entry;
use Magna\Settings\GeneralSettings;

/**
 * Resolves `{"$bind": "source.key"}` values in block data at render time
 * (docs/block-document-format.md §5 — bindings were valid to STORE since
 * Phase A; this is where they finally resolve).
 *
 * v1 sources: the entry being rendered (`entry.<attribute>`, allowlisted —
 * a binding must not become a read primitive over arbitrary model state)
 * and the site (`site.name`). Unknown sources resolve to an EMPTY STRING,
 * never an error and never a leak: a page whose binding outlived its
 * source renders a gap, not a stack trace and not somebody's data.
 *
 * Resolution feeds the view payload only — the stored document keeps its
 * bindings, which is what makes them bindings.
 */
class BindingResolver
{
    /** Entry attributes a binding may read. */
    private const ENTRY_SOURCES = ['title', 'slug', 'path', 'published_at', 'updated_at'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, ?Entry $entry): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && array_key_exists('$bind', $value)) {
                $data[$key] = $this->value($value['$bind'], $entry);
            }
        }

        return $data;
    }

    /**
     * The sources a picker can offer, given a context entry.
     *
     * @return array<string, string> source => human label
     */
    public function sources(): array
    {
        $sources = ['site.name' => 'Site name'];
        foreach (self::ENTRY_SOURCES as $attribute) {
            $sources['entry.'.$attribute] = 'Page '.str_replace('_', ' ', $attribute);
        }

        return $sources;
    }

    private function value(mixed $bind, ?Entry $entry): string
    {
        if (! is_string($bind)) {
            return '';
        }

        if ($bind === 'site.name') {
            $name = GeneralSettings::get()->site_name;

            return is_string($name) ? $name : '';
        }

        if (str_starts_with($bind, 'entry.') && $entry !== null) {
            $attribute = substr($bind, 6);
            if (! in_array($attribute, self::ENTRY_SOURCES, true)) {
                return '';
            }

            $value = $entry->getAttribute($attribute);
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d');
            }

            return is_string($value) || is_numeric($value) ? (string) $value : '';
        }

        return '';
    }
}
