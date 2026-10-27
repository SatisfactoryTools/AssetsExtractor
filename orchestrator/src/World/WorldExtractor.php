<?php

declare(strict_types=1);

namespace App\World;

use Symfony\Component\Process\Process;

/**
 * Invokes the .NET world extractor (world-extractor-net) against an install,
 * producing the world source (resource nodes with locations), the collectibles
 * list and the map tile set in one pass over the persistent level.
 */
final class WorldExtractor
{
    public const WORLD_FILE = 'world.json';
    public const COLLECTIBLES_FILE = 'collectibles.json';
    public const MAP_DIR = 'map';

    /**
     * @param list<string> $engineCandidates
     */
    public function __construct(
        private readonly string $dotnet,
        private readonly string $dll,
        private readonly array $engineCandidates,
    ) {
    }

    /**
     * @param list<string>|null $engineCandidates same pinning rules as the image extractor
     */
    public function extract(
        string $installDir,
        string $outDir,
        ?string $gameVersion = null,
        ?callable $onProgress = null,
        ?array $engineCandidates = null,
    ): WorldExtraction {
        if (!is_file($this->dll)) {
            throw new \RuntimeException("World extractor DLL not found: {$this->dll} — build world-extractor-net (dotnet build -c Release).");
        }
        if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Cannot create world output dir: {$outDir}");
        }

        $world = $outDir . '/' . self::WORLD_FILE;
        $collectibles = $outDir . '/' . self::COLLECTIBLES_FILE;
        $mapDir = $outDir . '/' . self::MAP_DIR;
        foreach ([$world, $collectibles, $mapDir . '/map.json'] as $stale) {
            @unlink($stale);
        }

        $args = [
            $this->dotnet, $this->dll,
            '--install-dir', $installDir,
            '--out', $world,
            '--collectibles-out', $collectibles,
            '--map-out', $mapDir,
            '--engine-candidates', implode(',', $engineCandidates ?? $this->engineCandidates),
        ];
        if ($gameVersion !== null && $gameVersion !== '') {
            $args[] = '--game-version';
            $args[] = $gameVersion;
        }

        $process = new Process($args);
        $process->setTimeout(1800.0);
        $process->run(function (string $type, string $buffer) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($buffer);
            }
        });

        if (!$process->isSuccessful() || !is_file($world) || !is_file($collectibles) || !is_file($mapDir . '/map.json')) {
            throw new \RuntimeException(
                "World extractor failed (exit {$process->getExitCode()}).\n" .
                substr($process->getErrorOutput() . $process->getOutput(), -2000)
            );
        }

        /** @var array{id:string} $map */
        $map = json_decode((string) file_get_contents($mapDir . '/map.json'), true, flags: JSON_THROW_ON_ERROR);

        return new WorldExtraction($world, $collectibles, $mapDir, (string) $map['id'], trim($process->getOutput()));
    }
}
