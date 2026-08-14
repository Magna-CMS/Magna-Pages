<?php

declare(strict_types=1);

namespace Magna\Pages\Render;

use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Blocks\Resolution\ResolverBudget;
use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;
use Magna\Settings\GeneralSettings;
use Throwable;

/**
 * Resolves `{"$bind": "source.key"}` values in block data at render time
 * (docs/block-document-format.md §5 — bindings were valid to STORE since
 * Phase A; this is where they finally resolve).
 *
 * v1 sources: the entry being rendered (`entry.<attribute>`, allowlisted —
 * a binding must not become a read primitive over arbitrary model state),
 * the site (`site.name`), and plugin dynamic tags (`tag.<handle>` through
 * the core DynamicTagRegistry — a tag that throws degrades to an empty
 * string and a log line, never a broken page). Unknown sources resolve to
 * an EMPTY STRING, never an error and never a leak: a page whose binding
 * outlived its source renders a gap, not a stack trace and not somebody's
 * data.
 *
 * Resolution feeds the view payload only — the stored document keeps its
 * bindings, which is what makes them bindings.
 */
class BindingResolver
{
    /** Entry attributes a binding may read. */
    private const ENTRY_SOURCES = ['title', 'slug', 'path', 'published_at', 'updated_at'];

    public function __construct(
        private readonly DynamicTagRegistry $tags,
        private readonly SchemaRegistry $schemas,
        private readonly ResolverBudget $budget,
    ) {}

    /**
     * The inline-tag token an editor types INSIDE text: {tag:vendor.name}.
     * Deliberately not a general expression syntax — one shape, resolving
     * through the same allowlisted sources as field bindings.
     */
    public const INLINE_TAG_PATTERN = '/\{tag:([a-z0-9][a-z0-9._-]*)\}/';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, ?Entry $entry): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && array_key_exists('$bind', $value)) {
                $data[$key] = $this->value($value['$bind'], $entry);
            } elseif (is_string($value) && str_contains($value, '{tag:')) {
                // Inline tags inside text values. The substituted value is
                // plain text: views escape whole strings, and richtext runs
                // the sanitizer AFTER substitution — either way a tag value
                // cannot inject markup.
                $data[$key] = (string) preg_replace_callback(
                    self::INLINE_TAG_PATTERN,
                    fn (array $m): string => $this->value('tag.'.$m[1], $entry),
                    $value,
                );
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
        foreach ($this->tags->all() as $handle => $tag) {
            $sources['tag.'.$handle] = $tag->label();
        }
        ksort($sources);

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

        if (str_starts_with($bind, 'tag.')) {
            $tag = $this->tags->get(substr($bind, 4));
            if ($tag === null) {
                return '';
            }
            try {
                /*
                 * Through the budget, so a page carrying forty slow tags
                 * costs the visitor a bounded wait and some gaps rather than
                 * an unbounded one. A refused tag is null here and reads as
                 * the same gap an unknown tag renders.
                 */
                return $this->budget->spend('tag', $tag->handle(), fn (): string => $tag->resolve()) ?? '';
            } catch (Throwable $e) {
                // A misbehaving tag costs its own gap, never the page.
                logger()->warning("Dynamic tag [{$tag->handle()}] failed to resolve: {$e->getMessage()}");

                return '';
            }
        }

        if (str_starts_with($bind, 'entry.') && $entry !== null) {
            $attribute = substr($bind, 6);
            if (! in_array($attribute, self::ENTRY_SOURCES, true)
                && ! $this->fieldIsPublic($entry, $attribute)
            ) {
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

    /**
     * §C4: beyond the fixed allowlist, a binding may read a schema field
     * ONLY when the entry's own type flags it publicOnFrontend — the lever
     * that lets a collection single template show an article's body
     * without turning bindings into a read primitive over model state.
     */
    private function fieldIsPublic(Entry $entry, string $attribute): bool
    {
        $handle = $entry->getHandle();
        if ($handle === null) {
            return false;
        }

        $field = $this->schemas->get($handle)?->getField($attribute);

        return $field !== null && $field->publicOnFrontend();
    }
}
