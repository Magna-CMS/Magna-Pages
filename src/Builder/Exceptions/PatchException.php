<?php

declare(strict_types=1);

namespace Magna\Pages\Builder\Exceptions;

use RuntimeException;

/**
 * A patch that cannot be applied: malformed shape, an unknown verb, a
 * pointer into a node that does not exist, or an operation the actor is not
 * allowed to perform. Callers render it as a 422 — the document is never
 * partially written (PatchApplier works on a copy).
 */
class PatchException extends RuntimeException {}
