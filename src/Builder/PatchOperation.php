<?php

declare(strict_types=1);

namespace Magna\Pages\Builder;

use Magna\Pages\Builder\Exceptions\PatchException;

/**
 * One builder edit, in JSON-Patch (RFC 6902) shape.
 *
 * Deliberately a narrow subset: add / remove / replace / move. `copy` and
 * `test` buy nothing the builder needs and every extra verb is another path
 * through the authorizer. Anything else is refused at parse time rather than
 * silently ignored — a client that sends an unknown verb has a bug, and a
 * bug that fails loudly here cannot become a silent data loss later.
 */
final class PatchOperation
{
    public const OPS = ['add', 'remove', 'replace', 'move'];

    /**
     * @param  list<string>  $segments  Decoded pointer segments of `path`
     * @param  list<string>|null  $fromSegments  Decoded pointer segments of `from` (move only)
     */
    private function __construct(
        public readonly string $op,
        public readonly string $path,
        public readonly array $segments,
        public readonly mixed $value,
        public readonly ?string $from,
        public readonly ?array $fromSegments,
    ) {}

    /**
     * @param  array<mixed, mixed>  $raw
     *
     * @throws PatchException
     */
    public static function fromArray(array $raw): self
    {
        $op = $raw['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPS, true)) {
            throw new PatchException('Unsupported patch operation.');
        }

        $path = $raw['path'] ?? null;
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            throw new PatchException('A patch operation needs an absolute JSON Pointer path.');
        }

        $from = null;
        $fromSegments = null;
        if ($op === 'move') {
            $rawFrom = $raw['from'] ?? null;
            if (! is_string($rawFrom) || ! str_starts_with($rawFrom, '/')) {
                throw new PatchException('A move operation needs an absolute "from" pointer.');
            }
            $from = $rawFrom;
            $fromSegments = self::segments($rawFrom);
        }

        return new self(
            op: $op,
            path: $path,
            segments: self::segments($path),
            value: $raw['value'] ?? null,
            from: $from,
            fromSegments: $fromSegments,
        );
    }

    /**
     * Split a JSON Pointer into decoded segments (RFC 6901 escaping).
     *
     * @return list<string>
     *
     * @throws PatchException
     */
    private static function segments(string $pointer): array
    {
        $segments = [];

        foreach (explode('/', substr($pointer, 1)) as $segment) {
            $segments[] = str_replace(['~1', '~0'], ['/', '~'], $segment);
        }

        if ($segments === []) {
            throw new PatchException('A patch pointer cannot address the whole document.');
        }

        return $segments;
    }
}
