<?php

declare(strict_types=1);

namespace App\Mods\ContentLib;

/**
 * A mod's ContentLib folder: JSON files that ContentLib turns into game content when
 * the game starts, by content type (Items, Recipes, Schematics, their *Patches, VisualKits,
 * CDOs) plus PNG icons.
 */
final class ContentLibFolder
{
    public const ITEMS = 'Items';
    public const RECIPES = 'Recipes';
    public const SCHEMATICS = 'Schematics';
    public const ITEM_PATCHES = 'ItemPatches';
    public const RECIPE_PATCHES = 'RecipePatches';
    public const SCHEMATIC_PATCHES = 'SchematicPatches';
    public const VISUAL_KITS = 'VisualKits';
    public const CDOS = 'CDOs';

    /** @var list<string> files that could not be read, with the reason */
    private array $errors = [];

    private function __construct(
        public readonly string $reference,
        private readonly string $dir,
    ) {
    }

    public static function in(string $modDir, string $reference): ?self
    {
        $dir = $modDir . '/ContentLib';

        return is_dir($dir) ? new self($reference, $dir) : null;
    }

    /** @return list<ContentLibFile> sorted by name, the order ContentLib loads them in */
    public function files(string $type): array
    {
        $files = [];
        foreach ($this->jsonFiles($type) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $contents = (string) file_get_contents($path);
            $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
            $target = null;
            if (str_ends_with($type, 'Patches') || $type === self::CDOS) {
                // A patch starts with a comment line naming its target: //Game/Path/Asset.Asset_C
                if (preg_match('#^\s*//\s*(\S+)#', $contents, $m)) {
                    $target = (string) preg_replace('#^.*[./]#', '', $m[1]);
                }
            }
            $data = self::decode($contents);
            if ($data === null) {
                $this->errors[] = "{$type}/{$name}.json is not valid JSON";
                continue;
            }
            $files[] = new ContentLibFile($name, $data, $target);
        }
        usort($files, static fn(ContentLibFile $a, ContentLibFile $b) => strcmp($a->name, $b->name));

        return $files;
    }

    public function count(string $type): int
    {
        return \count($this->jsonFiles($type));
    }

    /** @return array<string,mixed>|null */
    public function kit(string $name): ?array
    {
        foreach ($this->jsonFiles(self::VISUAL_KITS) as $path) {
            if (strcasecmp(pathinfo($path, PATHINFO_FILENAME), $name) === 0) {
                return self::decode((string) file_get_contents($path));
            }
        }

        return null;
    }

    /** Path of a PNG in the Icons folder, by file name with or without .png. */
    public function icon(string $name): ?string
    {
        $name = preg_replace('/\.png$/i', '', $name);
        foreach ($this->find('Icons', '/\.png$/i') as $path) {
            if (strcasecmp(pathinfo($path, PATHINFO_FILENAME), (string) $name) === 0) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return array_values(array_unique($this->errors));
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $contents): ?array
    {
        $contents = (string) preg_replace('#^\s*//[^\n]*\n#m', '', $contents);
        $data = json_decode($contents, true);
        if (!\is_array($data)) {
            // ContentLib reads JSON more loosely than PHP; trailing commas are common.
            $data = json_decode((string) preg_replace('/,(\s*[}\]])/', '$1', $contents), true);
        }

        return \is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private function jsonFiles(string $type): array
    {
        return $this->find($type, '/\.json$/i');
    }

    /** @return list<string> files of a type folder, which may have subfolders */
    private function find(string $type, string $pattern): array
    {
        $dir = $this->dir . '/' . $type;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
