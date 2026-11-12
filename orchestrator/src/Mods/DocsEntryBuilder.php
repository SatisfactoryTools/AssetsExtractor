<?php

declare(strict_types=1);

namespace App\Mods;

use SFTools\Data\Parser\ClassData;
use SFTools\Data\Schema\DocsSchema;
use SFTools\Data\Unpacker;

/**
 * Turns dumped mod classes into complete Docs entries. A class default object only
 * stores what differs from its parent, so each class is laid over its parent: a base
 * game class's own Docs entry, another mod class, or the inferred C++ defaults.
 */
final class DocsEntryBuilder
{
    private const FUEL_KEYS = ['mDefaultFuelClasses', 'mSupplementalResourceClass', 'mRequiresSupplementalResource'];

    /** DocsParser stops on a value of these it does not know, so such classes are left out. */
    private const ENUM_KEYS = ['mForm', 'mStackSize', 'mType', 'mEquipmentSlot'];

    private const CONTENT_KEYS = [
        'mIngredients', 'mProduct', 'mStackSize', 'mForm', 'mManufacturingSpeed', 'mPowerConsumption',
        'mPowerProduction', 'mBuildableClass', 'mUnlocks', 'mCost', 'mDefaultFuelClasses',
    ];

    /** @var array<string,array<string,mixed>> class path => dumped class */
    private array $classes = [];

    /** @var array<string,string> class path => mod reference */
    private array $owners = [];

    /** @var array<string,array<string,mixed>|string> class path => resolved {native, entry, own} or failure reason */
    private array $resolved = [];

    /** @var array<string,string> old building class name => new one */
    private array $renames = [];

    /** @var array<string,true> class names emitted so far, across all mods */
    private array $emitted = [];

    /** @var array<string,true> base-game packages already asked for */
    private array $requested = [];

    /** @var array<string,string> item class name => AWESOME Sink points */
    private array $sinkPoints = [];

    /**
     * @param array<string,string> $classMap class name => /Script/FactoryGame.<native class>
     */
    private const TEXT_KEYS = ['mDisplayName', 'mDescription', 'mAbbreviatedDisplayName', 'mSmallIcon', 'mPersistentBigIcon'];

    public function __construct(
        private readonly DocsFile $docs,
        private readonly NativeDefaults $defaults,
        private readonly array $classMap = [],
        private readonly bool $replaceExisting = false,
    ) {
    }

    /** @param list<array<string,mixed>> $classes */
    public function addClasses(string $owner, array $classes): void
    {
        foreach ($classes as $class) {
            $path = $class['packagePath'] . '.' . $class['className'];
            $this->classes[$path] = $class;
            $this->owners[$path] = $owner;
            unset($this->resolved[$path]);
        }
    }

    /**
     * Sink points live in data tables (rows of ItemClass + Points), not on the items.
     * @param list<array{path:string,rows:array<string,array<string,mixed>>}> $tables
     */
    public function addSinkTables(array $tables): void
    {
        foreach ($tables as $table) {
            foreach ($table['rows'] as $row) {
                if (!isset($row['ItemClass'], $row['Points']) || !\is_string($row['ItemClass'])) {
                    continue;
                }
                $item = self::className($row['ItemClass']);
                $overridden = (int) ($row['OverriddenResourceSinkPoints'] ?? -1);
                if ($item !== '') {
                    $this->sinkPoints[$item] = (string) ($overridden >= 0 ? $overridden : (int) $row['Points']);
                }
            }
        }
    }

    /**
     * Base-game parents that the Docs do not list, so their own values must be read from the paks.
     * @return list<string> package paths
     */
    public function missingBaseParents(): array
    {
        $missing = [];
        foreach ($this->classes as $class) {
            $super = $class['super'] ?? null;
            if (!\is_string($super) || !str_starts_with($super, '/Game/') || isset($this->classes[$super])) {
                continue;
            }
            $package = substr($super, 0, (int) strrpos($super, '.'));
            if ($this->docs->find(self::shortName($super)) === null && !isset($this->requested[$package])) {
                $missing[$package] = true;
                $this->requested[$package] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * Docs entries for one mod's classes.
     * @return array{entries:list<array{native:string,entry:array<string,mixed>}>,report:ModReport}
     */
    public function build(string $reference): array
    {
        $report = new ModReport($reference);
        $candidates = [];
        $skipped = [];
        foreach ($this->classes as $path => $class) {
            if ($this->owners[$path] !== $reference) {
                continue;
            }
            $report->classes++;
            $resolved = $this->resolve($path);
            $contentLike = self::isContentLike($class);

            if (\is_string($resolved)) {
                if ($contentLike) {
                    $report->skip($class['className'], $resolved);
                }
                $skipped[$class['className']] = true;
                continue;
            }
            if (!$this->defaults->has($resolved['native'])) {
                $report->ignoreType($resolved['native']);
                continue;
            }
            if (($class['abstract'] ?? false) === true) {
                continue;
            }
            $invalid = $this->invalidEnum($resolved);
            if ($invalid !== null) {
                $report->skip($class['className'], $invalid);
                $skipped[$class['className']] = true;
                continue;
            }
            if (isset($this->emitted[$class['className']]) || (!$this->replaceExisting && $this->docs->find($class['className']) !== null)) {
                $report->skip($class['className'], 'another class with this name already exists');
                continue;
            }
            $this->emitted[$class['className']] = true;
            $candidates[] = $resolved + ['fromMod' => !str_starts_with($path, '/Game/')];
        }

        $this->renameBuildings($candidates, $report);
        foreach ($candidates as &$candidate) {
            $candidate['entry'] = $this->applyRenames($candidate['entry']);
        }
        unset($candidate);
        $this->dropOrphanDescriptors($candidates, $skipped, $report);
        $this->nameDescriptors($candidates);

        $entries = [];
        $unnamed = [];
        foreach ($candidates as $resolved) {
            $entry = $resolved['entry'];
            if (array_intersect(self::FUEL_KEYS, $resolved['own']) !== [] && \array_key_exists('mFuel', $entry)) {
                $entry['mFuel'] = $this->fuels($entry, $candidates);
            }
            if (str_ends_with($resolved['native'], 'Recipe') && ($entry['mDisplayNameOverride'] ?? 'False') !== 'True') {
                $entry['mDisplayName'] = $this->productName($entry, $candidates) ?? ($entry['mDisplayName'] ?? '');
            }
            // DocsParser drops nameless schematics as discontinued, and every recipe only they unlock with them.
            if (($resolved['fromMod'] ?? false) && str_ends_with($resolved['native'], 'Schematic') && trim((string) ($entry['mDisplayName'] ?? '')) === '') {
                $entry['mDisplayName'] = self::readableName($resolved['className']);
                $unnamed[] = $resolved['className'];
            }
            if (isset($this->sinkPoints[$resolved['className']]) && \array_key_exists('mResourceSinkPoints', $entry)) {
                $entry['mResourceSinkPoints'] = $this->sinkPoints[$resolved['className']];
            }
            if (\array_key_exists('mSmallIcon', $entry) && \is_string($entry['mPersistentBigIcon'] ?? null) && $entry['mPersistentBigIcon'] !== 'None') {
                $entry['mSmallIcon'] = $entry['mPersistentBigIcon'];
            }
            $allowed = $this->defaults->forClass($resolved['native']) + ['ClassName' => '', 'FullName' => ''];
            $entries[] = ['native' => $resolved['native'], 'entry' => array_intersect_key($entry, $allowed)];
            $report->count($resolved['native']);
        }

        $entries = $this->asciiSafe($entries, $report);

        if ($unnamed !== []) {
            $report->note(sprintf('%d schematic(s) have no name (%s); they are named after their class. Mods often grant such schematics in code, depending on settings, so their recipes may not all be available together in the game',
                \count($unnamed), implode(', ', $unnamed)));
        }

        return ['entries' => $entries, 'report' => $report];
    }

    /**
     * DocsParser's tokenizer only takes ASCII in class paths, so a class like "Recipe_Foo™_C" fails the whole parse.
     * The same conversion runs on names and on every reference, which keeps them matching.
     * @param list<array{native:string,entry:array<string,mixed>}> $entries
     * @return list<array{native:string,entry:array<string,mixed>}>
     */
    private function asciiSafe(array $entries, ModReport $report): array
    {
        $renamed = [];
        foreach ($entries as &$item) {
            array_walk_recursive($item['entry'], static function (mixed &$value, string|int $key) use (&$renamed): void {
                // Item icons are read as plain strings and must keep the real path for the image extractor.
                if (!\is_string($value) || \in_array($key, self::TEXT_KEYS, true) || str_starts_with($value, 'Texture2D ')
                    || preg_match('/[^\x00-\x7F]/', $value) !== 1) {
                    return;
                }
                $safe = self::toAscii($value);
                if ($key === 'ClassName') {
                    $renamed[$value] = $safe;
                }
                $value = $safe;
            });
        }
        unset($item);

        foreach ($renamed as $old => $new) {
            $report->note("{$old} was renamed to {$new}, because the Docs parser only reads ASCII class names");
        }

        return $entries;
    }

    private static function toAscii(string $value): string
    {
        return (string) preg_replace_callback('/[^\x00-\x7F]+/u', static function (array $match): string {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $match[0]); // @ intentional
            $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', \is_string($transliterated) ? $transliterated : '');

            return $clean === '' || $clean === null ? '_' : $clean;
        }, $value);
    }

    private static function readableName(string $className): string
    {
        $name = (string) preg_replace(['/^(Schematic|Schem)_/', '/_C$/'], '', $className);

        return trim((string) preg_replace('/[_\-]+/', ' ', $name));
    }

    /** @return array{native:string,entry:array<string,mixed>,own:list<string>}|string */
    private function resolve(string $path, int $depth = 0): array|string
    {
        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }
        if ($depth > 32) {
            return 'its parent classes form a loop';
        }

        $class = $this->classes[$path] ?? null;
        if ($class === null) {
            $docs = $this->docs->find(self::shortName($path));

            return $docs !== null
                ? ['native' => $docs['native'], 'entry' => $docs['entry'], 'own' => []]
                : 'its parent ' . self::shortName($path) . ' could not be read';
        }

        $super = $class['super'] ?? null;
        $mapped = $this->classMap[$class['className']] ?? null;
        if ($mapped !== null) {
            $parent = ['native' => $mapped, 'entry' => $this->defaults->forClass($mapped), 'own' => []];
        } elseif (!\is_string($super) || $super === '') {
            $parent = 'it extends a class from the mod\'s own code';
        } elseif (str_starts_with($super, '/Script/FactoryGame.')) {
            $parent = ['native' => $super, 'entry' => $this->defaults->forClass($super), 'own' => []];
        } elseif (str_starts_with($super, '/Script/')) {
            $parent = 'it extends ' . $super;
        } else {
            $parent = $this->resolve($super, $depth + 1);
        }

        if (\is_string($parent)) {
            return $this->resolved[$path] = $parent;
        }

        $entry = $parent['entry'];
        foreach ((array) $class['properties'] as $key => $value) {
            $entry[$key] = \is_array($value) ? $this->completeSubobjects($value) : $value;
        }
        $entry = ['ClassName' => $class['className'], 'FullName' => $class['fullName']] + $entry;

        return $this->resolved[$path] = [
            'native' => $parent['native'],
            'entry' => $entry,
            'own' => array_values(array_unique([...$parent['own'], ...array_keys((array) $class['properties'])])),
            'className' => $class['className'],
        ];
    }

    /**
     * @param list<mixed> $subobjects
     * @return list<mixed>
     */
    private function completeSubobjects(array $subobjects): array
    {
        return array_map(function (mixed $subobject): mixed {
            if (!\is_array($subobject) || !isset($subobject['Class'])) {
                return $subobject;
            }

            return ['Class' => $subobject['Class']] + $subobject + $this->defaults->forSubobject((string) $subobject['Class']);
        }, $subobjects);
    }

    /** @param array{native:string,entry:array<string,mixed>} $resolved */
    private function invalidEnum(array $resolved): ?string
    {
        foreach (self::ENUM_KEYS as $key) {
            $value = $resolved['entry'][$key] ?? null;
            $known = $this->docs->values($resolved['native'], $key);
            if (\is_string($value) && $known !== [] && !isset($known[$value])) {
                return "{$key} is {$value}, which the game itself never uses";
            }
        }

        return null;
    }

    /**
     * DocsParser pairs a building with its descriptor by name (Build_X_C ↔ Desc_X_C).
     * Some mods name them differently, so the building is renamed after its descriptor.
     * @param list<array{native:string,entry:array<string,mixed>,own:list<string>,className:string}> $candidates
     */
    private function renameBuildings(array &$candidates, ModReport $report): void
    {
        $byName = [];
        foreach ($candidates as $i => $candidate) {
            $byName[$candidate['className']] = $i;
        }
        $taken = fn(string $name): bool => isset($byName[$name]) || isset($this->emitted[$name]) || $this->docs->find($name) !== null;
        $rename = function (string $old, string $new) use (&$candidates, &$byName): void {
            $index = $byName[$old];
            $this->renames[$old] = $new;
            $candidates[$index]['className'] = $new;
            $candidates[$index]['entry']['ClassName'] = $new;
            unset($byName[$old], $this->emitted[$old]);
            $byName[$new] = $index;
            $this->emitted[$new] = true;
        };

        foreach (array_keys($candidates) as $i) {
            $buildable = $candidates[$i]['entry']['mBuildableClass'] ?? null;
            if (!\is_string($buildable) || $buildable === '' || $buildable === 'None') {
                continue;
            }
            $target = $this->renames[ClassData::parseBlueprintClass($buildable)] ?? ClassData::parseBlueprintClass($buildable);
            $descriptor = $candidates[$i]['className'];
            if (!isset($byName[$target]) || DocsSchema::convertBuildingClassName($target) === $descriptor) {
                continue;
            }

            if (stripos($descriptor, 'desc_') === 0 && !str_starts_with($descriptor, 'Desc_') && !$taken('Desc_' . substr($descriptor, 5))) {
                $fixed = 'Desc_' . substr($descriptor, 5);
                $rename($descriptor, $fixed);
                $report->note("{$descriptor} was renamed to {$fixed}");
                $descriptor = $fixed;
            }

            $newName = str_starts_with($descriptor, 'Desc_') ? 'Build_' . substr($descriptor, 5) : $descriptor;
            if ($newName === $target || DocsSchema::convertBuildingClassName($target) === $descriptor) {
                continue;
            }
            if ($taken($newName)) {
                $report->note("{$target} is built from {$descriptor}, but {$newName} already exists, so they stay unlinked");
                continue;
            }
            $rename($target, $newName);
            $report->note("{$target} was renamed to {$newName} to match its descriptor {$descriptor}");
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function applyRenames(array $entry): array
    {
        if ($this->renames === []) {
            return $entry;
        }
        $patterns = [];
        foreach ($this->renames as $old => $new) {
            $patterns['/\.' . preg_quote($old, '/') . '(?=[\'"),]|$)/'] = '.' . $new;
        }
        array_walk_recursive($entry, static function (mixed &$value, string|int $key) use ($patterns): void {
            if (\is_string($value) && $key !== 'ClassName') {
                $value = preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
            }
        });

        return $entry;
    }

    /**
     * The Docs list a generator's fuels with their supplemental resource and waste; the
     * class itself only names the fuel items.
     * @param array<string,mixed> $entry
     * @param list<array{entry:array<string,mixed>,className:string}> $candidates
     * @return list<array<string,string>>
     */
    private function fuels(array $entry, array $candidates): array
    {
        $supplemental = ($entry['mRequiresSupplementalResource'] ?? 'False') === 'True'
            ? self::className((string) ($entry['mSupplementalResourceClass'] ?? ''))
            : '';

        $fuels = [];
        foreach ((array) Unpacker::unpack((string) ($entry['mDefaultFuelClasses'] ?? '')) as $fuelPath) {
            if (!\is_string($fuelPath) || $fuelPath === '') {
                continue;
            }
            $fuel = self::className($fuelPath);
            $item = $this->findEntry($fuel, $candidates);
            $waste = \is_array($item) ? self::className((string) ($item['mSpentFuelClass'] ?? '')) : '';
            $fuels[] = [
                'mFuelClass' => $fuel,
                'mSupplementalResourceClass' => $supplemental,
                'mByproduct' => $waste,
                'mByproductAmount' => $waste !== '' ? (string) ($item['mAmountOfWaste'] ?? '') : '',
            ];
        }

        return $fuels;
    }

    /**
     * The game names a building descriptor after the building it places.
     * @param list<array{entry:array<string,mixed>,className:string}> $candidates
     */
    /**
     * A descriptor takes its name and every building value from its buildable, so without it the planner would get a nameless, empty building.
     * @param list<array{entry:array<string,mixed>,className:string}> $candidates
     * @param array<string,true> $skipped
     */
    private function dropOrphanDescriptors(array &$candidates, array $skipped, ModReport $report): void
    {
        $candidates = array_values(array_filter($candidates, function (array $candidate) use ($skipped, $report): bool {
            $buildable = $candidate['entry']['mBuildableClass'] ?? $candidate['entry']['mVehicleClass'] ?? null;
            if (!\is_string($buildable) || $buildable === '' || $buildable === 'None') {
                return true;
            }
            $buildableName = self::className($buildable);
            if (!isset($skipped[$buildableName]) || $this->docs->find($buildableName) !== null) {
                return true;
            }
            $report->skip($candidate['className'], "its building {$buildableName} was skipped");

            return false;
        }));
    }

    private function nameDescriptors(array &$candidates): void
    {
        foreach ($candidates as $i => $candidate) {
            $buildable = $candidate['entry']['mBuildableClass'] ?? $candidate['entry']['mVehicleClass'] ?? null;
            if (!\is_string($buildable) || $buildable === '' || $buildable === 'None') {
                continue;
            }
            $building = $this->findEntry(self::className($buildable), $candidates);
            if ($building === null) {
                continue;
            }
            foreach (['mDisplayName', 'mDescription'] as $key) {
                if (\is_string($building[$key] ?? null) && $building[$key] !== '') {
                    $candidates[$i]['entry'][$key] = $building[$key];
                }
            }
        }
    }

    /**
     * Recipes without their own name are named after their first product.
     * @param array<string,mixed> $entry
     * @param list<array{entry:array<string,mixed>,className:string}> $candidates
     */
    private function productName(array $entry, array $candidates): ?string
    {
        $products = Unpacker::unpack((string) ($entry['mProduct'] ?? ''));
        $first = \is_array($products) ? ($products[0]['ItemClass'] ?? null) : null;
        if (!\is_string($first)) {
            return null;
        }
        $item = $this->findEntry(self::className($first), $candidates);
        $name = $item['mDisplayName'] ?? null;

        return \is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param list<array{entry:array<string,mixed>,className:string}> $candidates
     * @return array<string,mixed>|null
     */
    private function findEntry(string $className, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['className'] === $className) {
                return $candidate['entry'];
            }
        }
        foreach ($this->resolved as $resolved) {
            if (\is_array($resolved) && ($resolved['className'] ?? null) === $className) {
                return $resolved['entry'];
            }
        }

        return $this->docs->find($className)['entry'] ?? null;
    }

    /** @param array<string,mixed> $class */
    private static function isContentLike(array $class): bool
    {
        if (preg_match('/^(Desc_|Build_|Recipe_|Schematic_|Research_)/', (string) $class['className'])) {
            return true;
        }

        return array_intersect(self::CONTENT_KEYS, array_keys((array) $class['properties'])) !== [];
    }

    private static function className(string $reference): string
    {
        $reference = trim($reference, " \"'");
        if ($reference === '' || $reference === 'None') {
            return '';
        }
        $parsed = ClassData::parseBlueprintClass($reference);

        return $parsed !== $reference ? $parsed : self::shortName($reference);
    }

    private static function shortName(string $path): string
    {
        $dot = strrpos($path, '.');

        return trim($dot === false ? $path : substr($path, $dot + 1), "\"'");
    }
}
