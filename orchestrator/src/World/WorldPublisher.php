<?php

declare(strict_types=1);

namespace App\World;

/**
 * Publishes a world extraction into the API data dir, content-addressed so builds
 * and versions with the same map or world share one copy:
 *   <api_data>/<maps_subdir>/<mapId>/{map.json,tiles/...}
 *   <api_data>/<world_subdir>/source-<hash>.json        (resource nodes, generator input)
 *   <api_data>/<world_subdir>/collectibles-<hash>.json
 *
 * Returns the references the API stamps into each variant's metadata, as paths
 * relative to the API's www dir (the same form as a version's dataPath).
 */
final class WorldPublisher
{
    private const HASH_LENGTH = 16;

    public function __construct(
        private readonly string $apiDataDir,
        private readonly string $mapsSubdir,
        private readonly string $worldSubdir,
        private readonly string $dataPathPrefix,
    ) {
    }

    /**
     * @return array{map:string,worldSource:string,collectibles:string}
     */
    public function publish(WorldExtraction $extraction): array
    {
        $this->publishMap($extraction->mapDir, $extraction->mapId);

        return [
            'map' => $extraction->mapId,
            'worldSource' => $this->publishFile($extraction->worldFile, 'source'),
            'collectibles' => $this->publishFile($extraction->collectiblesFile, 'collectibles'),
        ];
    }

    /** A map id is a content hash, so an existing directory already holds exactly these tiles. */
    private function publishMap(string $sourceDir, string $mapId): void
    {
        if (preg_match('/^[0-9a-f]+$/', $mapId) !== 1) {
            throw new \RuntimeException("Unexpected map id: {$mapId}");
        }
        $mapsDir = $this->dir($this->mapsSubdir);
        $dest = $mapsDir . '/' . $mapId;
        if (is_file($dest . '/map.json')) {
            return;
        }

        // Copied next to the target and renamed in one step, so the API never serves a half-written tile set.
        $tmp = $mapsDir . '/.' . $mapId . '.tmp';
        $this->removeTree($tmp);
        $this->copyTree($sourceDir, $tmp);
        $this->removeTree($dest);
        if (!rename($tmp, $dest)) {
            $this->removeTree($tmp);
            throw new \RuntimeException("Failed to place map tiles at {$dest}");
        }
    }

    private function publishFile(string $source, string $kind): string
    {
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new \RuntimeException("Cannot read {$source}");
        }
        $name = $kind . '-' . substr($hash, 0, self::HASH_LENGTH) . '.json';
        $dest = $this->dir($this->worldSubdir) . '/' . $name;
        if (!is_file($dest)) {
            $tmp = $dest . '.tmp';
            if (!copy($source, $tmp) || !rename($tmp, $dest)) {
                @unlink($tmp);
                throw new \RuntimeException("Failed to place {$dest}");
            }
        }

        return trim($this->dataPathPrefix, '/') . '/' . trim($this->worldSubdir, '/') . '/' . $name;
    }

    private function dir(string $subdir): string
    {
        $dir = rtrim($this->apiDataDir, '/') . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        return $dir;
    }

    private function copyTree(string $source, string $dest): void
    {
        if (!mkdir($dest, 0o775, true) && !is_dir($dest)) {
            throw new \RuntimeException("Cannot create {$dest}");
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $dest . '/' . substr($item->getPathname(), \strlen($source) + 1);
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Cannot create {$target}");
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Cannot copy {$item->getPathname()}");
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
