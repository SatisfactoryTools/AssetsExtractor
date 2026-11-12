<?php

declare(strict_types=1);

namespace App\Mods\ContentLib;

final class ContentLibFile
{
    /**
     * @param string $name file name without .json, which ContentLib uses as the class name
     * @param array<string,mixed> $data
     * @param string|null $target class name a patch changes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly ?string $target = null,
    ) {
    }
}
