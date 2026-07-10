<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Builds the image-extraction manifest from raw-export.json: the de-duplicated
 * set of UE texture assets referenced anywhere in the parsed data. This is the
 * contract handed to the .NET extractor (Phase 4).
 *
 * A reference looks like:
 *   "Texture2D /Game/FactoryGame/.../IconDesc_IronPlates_256.IconDesc_IronPlates_256"
 * We strip the "Texture2D " class prefix to get the object path CUE4Parse loads.
 */
final class ImageManifest
{
    /**
     * @return list<array{assetPath:string,objectName:string}> unique by assetPath, sorted
     */
    public static function extract(string $rawExportPath): array
    {
        $json = json_decode((string) file_get_contents($rawExportPath), true, flags: JSON_THROW_ON_ERROR);

        $paths = [];
        self::collect($json, $paths);

        $manifest = [];
        foreach (array_keys($paths) as $assetPath) {
            $manifest[] = [
                'assetPath' => $assetPath,
                'objectName' => self::objectName($assetPath),
            ];
        }

        usort($manifest, static fn(array $a, array $b) => strcmp($a['assetPath'], $b['assetPath']));

        return $manifest;
    }

    /**
     * @param list<array{assetPath:string,objectName:string}> $manifest
     */
    public static function write(array $manifest, string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create manifest dir: {$dir}");
        }
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Recursively find "Texture2D <path>" string values.
     *
     * @param mixed $node
     * @param array<string,true> $out asset path => true (used as a set)
     */
    private static function collect(mixed $node, array &$out): void
    {
        if (\is_array($node)) {
            foreach ($node as $value) {
                self::collect($value, $out);
            }

            return;
        }

        if (\is_string($node) && str_starts_with($node, 'Texture2D ')) {
            $assetPath = trim(substr($node, \strlen('Texture2D ')));
            if ($assetPath !== '') {
                $out[$assetPath] = true;
            }
        }
    }

    private static function objectName(string $assetPath): string
    {
        // "/Game/.../IconDesc_X_256.IconDesc_X_256" -> "IconDesc_X_256"
        $afterDot = str_contains($assetPath, '.') ? substr(strrchr($assetPath, '.'), 1) : $assetPath;

        return $afterDot !== '' ? $afterDot : basename($assetPath);
    }
}
