<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * An unpacked .smod archive: the plugin descriptor plus the cooked paks (and, for mods
 * with their own code, the DLLs).
 */
final class SmodPackage
{
    /**
     * @param list<array{reference:string,condition:string,optional:bool}> $dependencies
     */
    private function __construct(
        public readonly string $reference,
        public readonly string $friendlyName,
        public readonly string $version,
        public readonly string $dir,
        public readonly string $paksDir,
        public readonly bool $hasCode,
        public readonly array $dependencies,
    ) {
    }

    public static function extract(string $smodFile, string $targetDir): self
    {
        if (is_dir($targetDir)) {
            self::removeDir($targetDir);
        }
        if (!mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Cannot create {$targetDir}");
        }

        $zip = new \ZipArchive();
        if ($zip->open($smodFile) !== true) {
            throw new \RuntimeException("{$smodFile} is not a valid .smod (zip) file.");
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException("{$smodFile} contains an unsafe path: {$name}");
            }
        }
        $zip->extractTo($targetDir);
        $zip->close();

        return self::fromDir($targetDir);
    }

    public static function fromDir(string $dir): self
    {
        $descriptors = glob($dir . '/*.uplugin') ?: [];
        if ($descriptors === []) {
            throw new \RuntimeException("No .uplugin file in {$dir} — is this a Satisfactory mod?");
        }
        $descriptorFile = $descriptors[0];
        $descriptor = json_decode((string) file_get_contents($descriptorFile), true);
        if (!\is_array($descriptor)) {
            throw new \RuntimeException("Cannot read {$descriptorFile}.");
        }

        $dependencies = [];
        foreach ((array) ($descriptor['Plugins'] ?? []) as $plugin) {
            if (!\is_array($plugin) || !isset($plugin['Name'])) {
                continue;
            }
            $dependencies[] = [
                'reference' => (string) $plugin['Name'],
                'condition' => (string) ($plugin['SemVersion'] ?? '*'),
                'optional' => (bool) ($plugin['bOptional'] ?? $plugin['Optional'] ?? false),
            ];
        }

        $paksDir = $dir . '/Content/Paks';
        if (!is_dir($paksDir)) {
            throw new \RuntimeException("{$dir} has no Content/Paks folder — the mod ships no game content.");
        }

        return new self(
            reference: pathinfo($descriptorFile, PATHINFO_FILENAME),
            friendlyName: (string) ($descriptor['FriendlyName'] ?? pathinfo($descriptorFile, PATHINFO_FILENAME)),
            version: (string) ($descriptor['SemVersion'] ?? $descriptor['VersionName'] ?? '0.0.0'),
            dir: $dir,
            paksDir: $paksDir,
            hasCode: (glob($dir . '/Binaries/*/*.dll') ?: []) !== [] || (glob($dir . '/Binaries/*/*.so') ?: []) !== [],
            dependencies: $dependencies,
        );
    }

    private static function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
