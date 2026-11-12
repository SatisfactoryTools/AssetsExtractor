<?php

declare(strict_types=1);

namespace App\Mods\ContentLib;

/**
 * PNG icons from ContentLib folders. They are not textures in paks, so the data refers to
 * them by a made-up asset path, and they are scaled here the way the image extractor
 * scales textures, giving results in its format.
 */
final class ContentLibIcons
{
    /** @var array<string,string> asset path => PNG file */
    private array $files = [];

    /** The asset path the data uses for a PNG. */
    public function add(string $reference, string $file): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($file, PATHINFO_FILENAME));
        $assetPath = "/{$reference}/ContentLib/Icons/{$name}.{$name}";
        $this->files[$assetPath] = $file;

        return $assetPath;
    }

    public function has(string $assetPath): bool
    {
        return isset($this->files[$assetPath]);
    }

    /**
     * @param list<string> $assetPaths
     * @param list<int> $sizes
     * @return list<array<string,mixed>> assets in the image extractor's result format
     */
    public function render(array $assetPaths, string $outDir, array $sizes): array
    {
        $assets = [];
        foreach ($assetPaths as $assetPath) {
            $objectName = substr($assetPath, (int) strrpos($assetPath, '.') + 1);
            $asset = ['assetPath' => $assetPath, 'objectName' => $objectName, 'ok' => false, 'width' => 0, 'height' => 0, 'sizes' => null, 'error' => null];
            $source = @imagecreatefrompng($this->files[$assetPath] ?? ''); // @ intentional: failure is reported below
            if ($source === false) {
                $asset['error'] = 'not a readable PNG';
                $assets[] = $asset;
                continue;
            }
            $asset['width'] = imagesx($source);
            $asset['height'] = imagesy($source);
            $asset['sizes'] = [];
            foreach ($sizes as $size) {
                $target = imagecreatetruecolor($size, $size);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
                imagecopyresampled($target, $source, 0, 0, 0, 0, $size, $size, $asset['width'], $asset['height']);
                $relative = "{$size}/cl-{$objectName}.png";
                if (!is_dir("{$outDir}/{$size}") && !mkdir("{$outDir}/{$size}", 0o775, true) && !is_dir("{$outDir}/{$size}")) {
                    throw new \RuntimeException("Cannot create {$outDir}/{$size}");
                }
                imagepng($target, "{$outDir}/{$relative}", 9);
                $asset['sizes'][(string) $size] = ['path' => $relative, 'sha256' => hash_file('sha256', "{$outDir}/{$relative}")];
            }
            $asset['ok'] = true;
            $assets[] = $asset;
        }

        return $assets;
    }
}
