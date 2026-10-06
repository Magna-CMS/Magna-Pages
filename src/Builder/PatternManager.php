<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Illuminate\Support\Str;
use Magna\Blocks\PageTreeValidator;
use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * Saving and instantiating patterns (My Library).
 *
 * Validation happens at SAVE by wrapping the subtree into a synthetic
 * document and running the real structural validator — a broken pattern
 * refused once at save beats one refused on every page it is dropped into.
 *
 * Instantiation regenerates every node id: the document's id-uniqueness
 * rule means the same pattern inserted twice must be two families of ids,
 * and handing the client a fresh-id copy keeps id generation in exactly
 * two places (here and the client's own new-node path) rather than
 * scattered through insert call sites.
 */
final class PatternManager
{
    public function __construct(private readonly PageTreeValidator $validator) {}

    /**
     * @param  array<mixed, mixed>  $node  A section or block subtree
     *
     * @throws PatchException
     */
    public function save(string $name, string $kind, array $node, ?string $userId): Pattern
    {
        if (! in_array($kind, [Pattern::KIND_SECTION, Pattern::KIND_BLOCK], true)) {
            throw new PatchException('A pattern is a section or a block.');
        }

        $errors = $this->validator->validate($this->wrap($kind, $node));
        if ($errors !== []) {
            throw new PatchException($errors[0]);
        }

        return Pattern::query()->create([
            'name' => Str::limit(trim($name) !== '' ? trim($name) : 'Untitled pattern', 120, ''),
            'kind' => $kind,
            'document' => $node,
            'created_by' => $userId,
        ]);
    }

    /**
     * Save a pattern the site kit carried, replacing one of the same name.
     *
     * Named rather than id'd, because a kit crosses environments and an id is
     * an environment fact. Re-syncing a kit therefore updates the pattern an
     * editor already has rather than leaving them two that look identical.
     *
     * @param  array<mixed, mixed>  $node
     *
     * @throws PatchException
     */
    public function upsertNamed(string $name, string $kind, array $node): Pattern
    {
        $existing = Pattern::query()->where('name', trim($name))->first();

        if ($existing === null) {
            return $this->save($name, $kind, $node, null);
        }

        if (! in_array($kind, [Pattern::KIND_SECTION, Pattern::KIND_BLOCK], true)) {
            throw new PatchException('A pattern is a section or a block.');
        }

        $errors = $this->validator->validate($this->wrap($kind, $node));
        if ($errors !== []) {
            throw new PatchException($errors[0]);
        }

        $existing->update(['kind' => $kind, 'document' => $node]);

        return $existing;
    }

    /**
     * A fresh-id copy of the pattern's subtree, ready to insert.
     *
     * @return array<mixed, mixed>
     */
    public function instance(Pattern $pattern): array
    {
        return DocumentIds::fresh($pattern->document);
    }

    /**
     * Wrap a subtree into a full document the validator understands.
     *
     * @param  array<mixed, mixed>  $node
     * @return list<mixed>
     */
    private function wrap(string $kind, array $node): array
    {
        if ($kind === Pattern::KIND_SECTION) {
            return [$node];
        }

        return [[
            'id' => (string) Str::ulid(),
            'type' => 'section',
            'settings' => [],
            'columns' => [[
                'id' => (string) Str::ulid(),
                'span' => 12,
                'settings' => [],
                'blocks' => [$node],
            ]],
        ]];
    }
}
