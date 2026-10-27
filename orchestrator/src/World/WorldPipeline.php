<?php

declare(strict_types=1);

namespace App\World;

use App\Config;

/**
 * Extract + publish the world of an install: the shared step of `run` (every new
 * build) and `world` (re-extracting a build that is already published).
 */
final class WorldPipeline
{
    public function __construct(
        private readonly WorldExtractor $extractor,
        private readonly WorldPublisher $publisher,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            new WorldExtractor(
                (string) $config->get('extraction.dotnet', 'dotnet'),
                (string) $config->get('extraction.world_dll', ''),
                (array) $config->get('extraction.engine_candidates', ['GAME_UE5_6']),
            ),
            new WorldPublisher(
                (string) $config->require('publish.api_data_dir'),
                (string) $config->get('publish.maps_subdir', 'maps'),
                (string) $config->get('publish.world_subdir', 'world'),
                (string) $config->get('publish.data_path_prefix', 'data'),
            ),
        );
    }

    /**
     * @param list<string>|null $engineCandidates
     * @return array{map:string,worldSource:string,collectibles:string} the data-file metadata references
     */
    public function run(
        string $installDir,
        string $outDir,
        ?string $gameVersion,
        ?callable $onProgress = null,
        ?array $engineCandidates = null,
    ): array {
        $extraction = $this->extractor->extract($installDir, $outDir, $gameVersion, $onProgress, $engineCandidates);
        $references = $this->publisher->publish($extraction);
        file_put_contents(
            $outDir . '/references.json',
            json_encode($references, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $references;
    }
}
