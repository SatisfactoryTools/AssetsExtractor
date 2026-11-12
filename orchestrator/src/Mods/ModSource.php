<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * One mod of an import: its unpacked files and where they came from.
 */
final class ModSource
{
    /** @param list<string> $dependencies references of the mods it needs */
    public function __construct(
        public readonly SmodPackage $package,
        public readonly string $reference,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $sourceVersionId,
        public readonly array $dependencies,
    ) {
    }
}
