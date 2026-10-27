<?php

declare(strict_types=1);

namespace App\World;

/** Output files of one world-extractor run. */
final class WorldExtraction
{
    public function __construct(
        public readonly string $worldFile,
        public readonly string $collectiblesFile,
        public readonly string $mapDir,
        public readonly string $mapId,
        public readonly string $log,
    ) {
    }
}
