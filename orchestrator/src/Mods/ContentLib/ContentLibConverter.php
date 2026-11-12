<?php

declare(strict_types=1);

namespace App\Mods\ContentLib;

use App\Mods\ModEntries;
use App\Mods\ModReport;

/**
 * Turns a mod's ContentLib files into Docs entries, in ContentLib's own order: items,
 * recipes, schematics, then patches of existing classes. Field meanings follow
 * ContentLib's JSON schemas (github.com/budak7273/ContentLib_Documentation).
 */
final class ContentLibConverter
{
    private const FALLBACK_STACK_SIZE = 'SS_MEDIUM';

    /** Inferred defaults are the most common values, which must not leak into lists and icons of a new class. */
    private const BLANK = [
        'FGRecipe' => ['mIngredients' => '', 'mProduct' => '', 'mProducedIn' => '', 'mDisplayName' => ''],
        'FGSchematic' => ['mCost' => '', 'mUnlocks' => [], 'mSchematicDependencies' => [], 'mSchematicIcon' => 'None', 'mSmallSchematicIcon' => 'None', 'mDisplayName' => '', 'mDescription' => ''],
        'FGItemDescriptor' => ['mSmallIcon' => 'None', 'mPersistentBigIcon' => 'None', 'mDisplayName' => '', 'mDescription' => '', 'mAbbreviatedDisplayName' => ''],
    ];

    /** @var array<string,int> what was added or patched => count */
    private array $counts = [];

    /**
     * @param list<ContentLibFolder> $kitFolders folders visual kits are looked up in, the mod's own first
     */
    public function __construct(
        private readonly ContentLibFolder $folder,
        private readonly array $kitFolders,
        private readonly ModEntries $entries,
        private readonly ModReport $report,
        private readonly ContentLibIcons $icons,
    ) {
    }

    public function convert(): void
    {
        $items = $this->folder->files(ContentLibFolder::ITEMS);
        $recipes = $this->folder->files(ContentLibFolder::RECIPES);
        $schematics = $this->folder->files(ContentLibFolder::SCHEMATICS);

        $this->createItems($items);
        foreach ($recipes as $file) {
            $this->create($file, 'FGRecipe', ContentLibFolder::RECIPES, fn(array $entry) => $this->applyRecipe($entry, $file->data, false));
        }
        // Schematics may unlock each other, so all exist before their unlocks are filled in.
        foreach ($schematics as $file) {
            $this->create($file, 'FGSchematic', ContentLibFolder::SCHEMATICS, fn(array $entry) => $this->applySchematic($entry, $file, false, false));
        }
        foreach ($schematics as $file) {
            $className = $file->name . '_C';
            if ($this->entries->has($className) && $this->entries->native($className) === ModEntries::nativePath('FGSchematic')) {
                $this->entries->set($className, $this->applySchematic($this->entries->get($className), $file, false, true));
            }
        }
        foreach ($recipes as $file) {
            $this->unlockedBy($file);
        }

        $this->patch(ContentLibFolder::ITEM_PATCHES, 'item', fn(array $entry, ContentLibFile $file) => $this->applyItem($entry, $file->data));
        $this->patch(ContentLibFolder::RECIPE_PATCHES, 'recipe', fn(array $entry, ContentLibFile $file) => $this->applyRecipe($entry, $file->data, true));
        $this->patch(ContentLibFolder::SCHEMATIC_PATCHES, 'schematic', fn(array $entry, ContentLibFile $file) => $this->applySchematic($entry, $file, true, true));

        $this->patch(ContentLibFolder::CDOS, 'CDO edits', fn(array $entry, ContentLibFile $file) => $this->applyCdo($entry, $file));
        foreach ($this->folder->files(ContentLibFolder::CDOS) as $file) {
            $this->nameDescriptor($file);
        }
        foreach ($this->folder->errors() as $error) {
            $this->report->note("ContentLib: {$error}");
        }
        if ($this->counts !== []) {
            $this->report->note('ContentLib: ' . implode(', ', array_map(static fn(string $what, int $count) => "{$count} {$what}",
                array_keys($this->counts), $this->counts)));
        }
    }

    /** @param list<ContentLibFile> $files */
    private function createItems(array $files): void
    {
        foreach ($files as $file) {
            $native = match (true) {
                isset($file->data['FuelWasteItem']) => 'FGItemDescriptorNuclearFuel',
                isset($file->data['ResourceItem']) => 'FGResourceDescriptor',
                default => 'FGItemDescriptor',
            };
            $this->create($file, $native, ContentLibFolder::ITEMS, fn(array $entry) => $this->applyItem($entry, $file->data));
        }
        // An item's spent fuel may be another new item.
        foreach ($files as $file) {
            $waste = $file->data['FuelWasteItem'] ?? null;
            $className = $file->name . '_C';
            if (!\is_array($waste) || !$this->entries->has($className)) {
                continue;
            }
            $entry = $this->entries->get($className);
            $spent = isset($waste['SpentFuelClass']) ? $this->entries->resolve((string) $waste['SpentFuelClass']) : null;
            if ($spent !== null) {
                $entry['mSpentFuelClass'] = $this->entries->reference($spent);
            }
            if (isset($waste['AmountOfWaste'])) {
                $entry['mAmountOfWaste'] = (string) (int) $waste['AmountOfWaste'];
            }
            $this->entries->set($className, $entry);
        }
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $apply */
    private function create(ContentLibFile $file, string $native, string $type, callable $apply): void
    {
        $className = $file->name . '_C';
        if ($this->entries->has($className)) {
            $this->report->skip($className, 'ContentLib file, but a class with this name already exists');

            return;
        }
        if (!$this->entries->knowsNative($native)) {
            $this->report->skip($className, "ContentLib file of type {$native}, which SFTools does not read");

            return;
        }
        $blank = self::BLANK[$native] ?? self::BLANK['FGItemDescriptor'];
        $entry = ['ClassName' => $className] + array_intersect_key($blank, $this->entries->defaults($native)) + $this->entries->defaults($native);
        try {
            $entry = $apply($entry);
        } catch (SkipException $e) {
            $this->report->skip($className, $e->getMessage());

            return;
        }
        $this->entries->add($native, $entry, "/{$this->folder->reference}/ContentLib/{$type}");
        $this->report->count(ModEntries::nativePath($native));
        $this->counted(strtolower($type));
    }

    /** @param callable(array<string,mixed>,ContentLibFile):array<string,mixed> $apply */
    private function patch(string $type, string $what, callable $apply): void
    {
        foreach ($this->folder->files($type) as $file) {
            $target = $file->target !== null ? $this->entries->resolve($file->target) : null;
            if ($target === null || !$this->entries->has($target)) {
                $this->report->note("ContentLib: {$type}/{$file->name} patches " . ($file->target ?? 'a class it does not name') . ', which does not exist');
                continue;
            }
            try {
                $this->entries->set($target, $apply($this->entries->get($target), $file));
            } catch (SkipException $e) {
                $this->report->note("ContentLib: {$type}/{$file->name} is not applied: {$e->getMessage()}");
                continue;
            }
            $this->counted("{$what} patches");
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyItem(array $entry, array $data): array
    {
        $native = isset($data['FuelWasteItem']) ? 'FGItemDescriptorNuclearFuel' : 'FGItemDescriptor';
        $this->copy($entry, $data, ['Name' => 'mDisplayName', 'Description' => 'mDescription', 'NameShort' => 'mAbbreviatedDisplayName']);
        if (isset($data['Form'])) {
            $form = 'RF_' . strtoupper((string) $data['Form']);
            if (!isset($this->entries->enumValues($native, 'mForm')[$form])) {
                throw new SkipException("form {$data['Form']} is not a game value");
            }
            $entry['mForm'] = $form;
        }
        if (isset($data['StackSize'])) {
            $stack = 'SS_' . strtoupper((string) $data['StackSize']);
            if (!isset($this->entries->enumValues($native, 'mStackSize')[$stack])) {
                $this->report->note("ContentLib: stack size {$data['StackSize']} of {$entry['ClassName']} is not a game value, " . self::FALLBACK_STACK_SIZE . ' is used');
                $stack = self::FALLBACK_STACK_SIZE;
            }
            $entry['mStackSize'] = $stack;
        }
        foreach (['CanBeDiscarded' => 'mCanBeDiscarded', 'RememberPickUp' => 'mRememberPickUp'] as $key => $docsKey) {
            if (isset($data[$key])) {
                $entry[$docsKey] = $data[$key] ? 'True' : 'False';
            }
        }
        foreach (['EnergyValue' => 'mEnergyValue', 'RadioactiveDecay' => 'mRadioactiveDecay'] as $key => $docsKey) {
            if (isset($data[$key])) {
                $entry[$docsKey] = sprintf('%.6f', (float) $data[$key]);
            }
        }
        if (isset($data['ResourceSinkPoints'])) {
            $entry['mResourceSinkPoints'] = (string) (int) $data['ResourceSinkPoints'];
        }
        if (isset($data['VisualKit'])) {
            [$big, $small] = $this->kitIcons((string) $data['VisualKit']);
            if ($big !== null) {
                $entry['mPersistentBigIcon'] = $big;
                $entry['mSmallIcon'] = $big;
            }
            if ($small !== null && $big === null) {
                $entry['mPersistentBigIcon'] = $small;
                $entry['mSmallIcon'] = $small;
            }
        }

        return $entry;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyRecipe(array $entry, array $data, bool $patch): array
    {
        if (isset($data['Ingredients'])) {
            $list = $this->amounts((array) $data['Ingredients'], true);
            $entry['mIngredients'] = ($data['ClearIngredients'] ?? true) ? $list : ModEntries::joinLists((string) ($entry['mIngredients'] ?? ''), $list);
        }
        if (isset($data['Products'])) {
            $list = $this->amounts((array) $data['Products'], true);
            $entry['mProduct'] = ($data['ClearProducts'] ?? true) ? $list : ModEntries::joinLists((string) ($entry['mProduct'] ?? ''), $list);
        }
        if (isset($data['ProducedIn'])) {
            $builders = [];
            foreach ((array) $data['ProducedIn'] as $name) {
                $builder = $this->entries->resolve((string) $name);
                if ($builder === null) {
                    $this->report->note("ContentLib: {$entry['ClassName']} is made in {$name}, which does not exist; it was left out");
                    continue;
                }
                $builders[] = '"' . $this->entries->path($builder) . '"';
            }
            $list = '(' . implode(',', $builders) . ')';
            $entry['mProducedIn'] = ($data['ClearBuilders'] ?? true) ? $list : ModEntries::joinLists((string) ($entry['mProducedIn'] ?? ''), $list);
        }
        foreach ([
            'ManufacturingDuration' => 'mManufactoringDuration',
            'ManualManufacturingMultiplier' => 'mManualManufacturingMultiplier',
            'ManufacturingMenuPriority' => 'mManufacturingMenuPriority',
            'VariablePowerConsumptionConstant' => 'mVariablePowerConsumptionConstant',
            'VariablePowerConsumptionFactor' => 'mVariablePowerConsumptionFactor',
        ] as $key => $docsKey) {
            if (isset($data[$key])) {
                $entry[$docsKey] = sprintf('%.6f', (float) $data[$key]);
            }
        }

        // The game shows the first product's name unless the recipe overrides it.
        if (isset($data['Name']) && ($data['OverrideName'] ?? true)) {
            $entry['mDisplayName'] = (string) $data['Name'];
        } elseif (!$patch || ($data['OverrideName'] ?? null) === false || isset($data['Products'])) {
            $entry['mDisplayName'] = $this->firstProductName((string) ($entry['mProduct'] ?? '')) ?? ($entry['mDisplayName'] ?? '');
        }

        return $entry;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function applySchematic(array $entry, ContentLibFile $file, bool $patch, bool $unlocks): array
    {
        $data = $file->data;
        if (!$unlocks || $patch) {
            $this->copy($entry, $data, ['Name' => 'mDisplayName', 'Description' => 'mDescription']);
            if (isset($data['Type'])) {
                $type = 'EST_' . $data['Type'];
                if (!isset($this->entries->enumValues('FGSchematic', 'mType')[$type])) {
                    throw new SkipException("schematic type {$data['Type']} is not one SFTools reads");
                }
                $entry['mType'] = $type;
            }
            if (isset($data['Tier'])) {
                $entry['mTechTier'] = (string) (int) $data['Tier'];
            }
            foreach (['Time' => 'mTimeToComplete', 'MenuPriority' => 'mMenuPriority'] as $key => $docsKey) {
                if (isset($data[$key])) {
                    $entry[$docsKey] = sprintf('%.6f', (float) $data[$key]);
                }
            }
            if (isset($data['Cost']) || ($data['ClearCost'] ?? false)) {
                $list = $this->amounts((array) ($data['Cost'] ?? []), false);
                $entry['mCost'] = ($data['ClearCost'] ?? false) ? $list : ModEntries::joinLists((string) ($entry['mCost'] ?? ''), $list);
            }
            if (isset($data['VisualKit'])) {
                [$big, $small] = $this->kitIcons((string) $data['VisualKit']);
                $icon = $big ?? $small;
                if ($icon !== null) {
                    $entry['mSchematicIcon'] = sprintf('(ImageSize=(X=256.000000,Y=256.000000),ResourceObject="/Script/Engine.Texture2D\'%s\'")',
                        substr($icon, \strlen('Texture2D ')));
                    $entry['mSmallSchematicIcon'] = 'None';
                }
            }
        }
        if (!$unlocks) {
            return $entry;
        }

        $unlockLists = [
            'Recipes' => ['BP_UnlockRecipe_C', 'mRecipes', 'ClearRecipes'],
            'Schematics' => ['BP_UnlockSchematic_C', 'mSchematics', 'ClearSchematics'],
        ];
        foreach ($unlockLists as $key => [$class, $docsKey, $clearKey]) {
            if (!isset($data[$key]) && !($data[$clearKey] ?? false)) {
                continue;
            }
            $classes = $this->resolveAll((array) ($data[$key] ?? []), "{$file->name} unlocks");
            $entry['mUnlocks'] = ModEntries::addUnlock($entry['mUnlocks'] ?? [], $class, $docsKey, $this->entries->references($classes), (bool) ($data[$clearKey] ?? false));
        }
        if (isset($data['ScannableResources']) || ($data['ClearScannableResources'] ?? false)) {
            $resources = $this->resolveAll(array_map(static fn(mixed $r) => (string) (\is_array($r) ? ($r['Resource'] ?? '') : $r),
                (array) ($data['ScannableResources'] ?? [])), "{$file->name} adds to the scanner");
            $entry['mUnlocks'] = ModEntries::addUnlock($entry['mUnlocks'] ?? [], 'BP_UnlockScannableResource_C', 'mResourcesToAddToScanner',
                $this->entries->references($resources), (bool) ($data['ClearScannableResources'] ?? false));
        }
        if (isset($data['ItemsToGive']) || ($data['ClearItemsToGive'] ?? false)) {
            $entry['mUnlocks'] = ModEntries::addUnlock($entry['mUnlocks'] ?? [], 'BP_UnlockGiveItem_C', 'mItemsToGive',
                $this->amounts((array) ($data['ItemsToGive'] ?? []), false), (bool) ($data['ClearItemsToGive'] ?? false));
        }
        foreach (['InventorySlotsToGive' => ['BP_UnlockInventorySlot_C', 'mNumInventorySlotsToUnlock'], 'ArmSlotsToGive' => ['BP_UnlockArmEquipmentSlot_C', 'mNumArmEquipmentSlotsToUnlock']] as $key => [$class, $docsKey]) {
            if (isset($data[$key])) {
                $entry['mUnlocks'] = array_values(array_filter((array) ($entry['mUnlocks'] ?? []), static fn(array $u) => ($u['Class'] ?? '') !== $class));
                $entry['mUnlocks'][] = ['Class' => $class, $docsKey => (string) (int) $data[$key]];
            }
        }
        if (isset($data['DependsOn']) || ($data['ClearDeps'] ?? false)) {
            $dependencies = ($data['ClearDeps'] ?? false) ? [] : (\is_array($entry['mSchematicDependencies'] ?? null) ? $entry['mSchematicDependencies'] : []);
            $schematics = $this->resolveAll((array) ($data['DependsOn'] ?? []), "{$file->name} depends on");
            if ($schematics !== []) {
                $dependencies[] = [
                    'Class' => 'BP_SchematicPurchasedDependency_C',
                    'mSchematics' => $this->entries->references($schematics),
                    'mRequireAllSchematicsToBePurchased' => 'True',
                ];
            }
            $entry['mSchematicDependencies'] = $dependencies;
        }

        return $entry;
    }

    /**
     * A CDO file sets class properties directly ({"Class": …, "Edits": [{"Property": …, "Value": …}]}). Only
     * properties the Docs list for the class are kept; the rest do not reach the planner.
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function applyCdo(array $entry, ContentLibFile $file): array
    {
        $ignored = [];
        $applied = 0;
        foreach ((array) ($file->data['Edits'] ?? []) as $edit) {
            $property = (string) ($edit['Property'] ?? '');
            $value = $edit['Value'] ?? null;
            if ($property === '' || !\array_key_exists($property, $entry) || \is_array($entry[$property]) || !\is_scalar($value)) {
                $ignored[] = $property;
                continue;
            }
            $old = (string) $entry[$property];
            $entry[$property] = match (true) {
                \is_bool($value) => $value ? 'True' : 'False',
                is_numeric($value) && preg_match('/^-?\d+\.\d+$/', $old) === 1 => sprintf('%.6f', (float) $value),
                default => (string) $value,
            };
            $applied++;
        }
        if ($applied === 0) {
            throw new SkipException('it sets nothing SFTools reads');
        }
        if ($ignored !== []) {
            $this->report->note(sprintf('ContentLib: CDOs/%s also sets %s, which SFTools does not read', $file->name, implode(', ', array_unique($ignored))));
        }

        return $entry;
    }

    /** The planner names a building after its descriptor, which the game fills from the building. */
    private function nameDescriptor(ContentLibFile $file): void
    {
        $building = $file->target !== null ? $this->entries->resolve($file->target) : null;
        $descriptor = $building !== null && str_starts_with($building, 'Build_') ? $this->entries->resolve('Desc_' . substr($building, 6)) : null;
        if ($descriptor === null) {
            return;
        }
        $entry = $this->entries->get($descriptor);
        $changed = false;
        foreach ((array) ($file->data['Edits'] ?? []) as $edit) {
            $property = (string) ($edit['Property'] ?? '');
            if (\in_array($property, ['mDisplayName', 'mDescription'], true) && \is_string($edit['Value'] ?? null) && \array_key_exists($property, $entry)) {
                $entry[$property] = $edit['Value'];
                $changed = true;
            }
        }
        if ($changed) {
            $this->entries->set($descriptor, $entry);
        }
    }

    /** A recipe can add itself to schematics, base-game ones included. */
    private function unlockedBy(ContentLibFile $file): void
    {
        $recipe = $file->name . '_C';
        if (!isset($file->data['UnlockedBy']) || !$this->entries->has($recipe)) {
            return;
        }
        foreach ($this->resolveAll((array) $file->data['UnlockedBy'], "{$file->name} is unlocked by") as $schematic) {
            $this->entries->unlockRecipes($schematic, [$recipe]);
        }
    }

    /**
     * @param list<mixed> $amounts ContentLib ItemAmounts
     */
    private function amounts(array $amounts, bool $required): string
    {
        $resolved = [];
        $missing = [];
        foreach ($amounts as $amount) {
            $name = (string) ($amount['Item'] ?? '');
            $item = $this->entries->resolve($name);
            if ($item === null) {
                $missing[] = $name;
                continue;
            }
            $resolved[] = sprintf('(ItemClass="%s",Amount=%s)', $this->entries->reference($item), ModEntries::number((float) ($amount['Amount'] ?? 1)));
        }
        if ($missing !== [] && $required) {
            throw new SkipException('uses unknown items: ' . implode(', ', $missing));
        }
        foreach ($missing as $name) {
            $this->report->note("ContentLib: unknown item {$name} was left out");
        }

        return '(' . implode(',', $resolved) . ')';
    }

    /**
     * @param list<mixed> $names
     * @return list<string>
     */
    private function resolveAll(array $names, string $context): array
    {
        $classes = [];
        foreach ($names as $name) {
            $class = $this->entries->resolve((string) $name);
            if ($class === null) {
                $this->report->note("ContentLib: {$context} {$name}, which does not exist; it was left out");
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }

    private function firstProductName(string $products): ?string
    {
        if (!preg_match('/ItemClass="[^"]*\.([A-Za-z0-9_\-]+)\'?"/', $products, $m) || !$this->entries->has($m[1])) {
            return null;
        }
        $name = $this->entries->get($m[1])['mDisplayName'] ?? null;

        return \is_string($name) ? $name : null;
    }

    /**
     * Icons of a visual kit: a kit file (PNG names or texture paths) or an existing class whose icons it takes.
     * @return array{?string,?string} big and small icon, as "Texture2D /Path/Tex.Tex"
     */
    private function kitIcons(string $kit): array
    {
        foreach ($this->kitFolders as $folder) {
            $data = $folder->kit($kit);
            if ($data !== null) {
                return [$this->icon($folder, $data['BigIcon'] ?? null), $this->icon($folder, $data['SmallIcon'] ?? null)];
            }
        }
        $class = $this->entries->resolve($kit);
        if ($class !== null) {
            $entry = $this->entries->get($class);
            $big = $entry['mPersistentBigIcon'] ?? null;
            if (\is_string($big) && str_starts_with($big, 'Texture2D ')) {
                return [$big, \is_string($entry['mSmallIcon'] ?? null) ? $entry['mSmallIcon'] : $big];
            }
            if (preg_match('/Texture2D\'(.+?)\'/', (string) ($entry['mSchematicIcon'] ?? ''), $m)) {
                return ['Texture2D ' . $m[1], 'Texture2D ' . $m[1]];
            }
        }
        $this->report->note("ContentLib: visual kit {$kit} was not found, so its icon is missing");

        return [null, null];
    }

    private function icon(ContentLibFolder $folder, mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (str_starts_with($value, '/')) {
            $path = preg_replace('/^.*?\'(.*)\'$/', '$1', $value) ?? $value;

            return 'Texture2D ' . (str_contains(basename($path), '.') ? $path : $path . '.' . basename($path));
        }
        $file = $folder->icon($value);
        if ($file === null) {
            $this->report->note("ContentLib: icon {$value}.png was not found");

            return null;
        }

        return 'Texture2D ' . $this->icons->add($folder->reference, $file);
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $data
     * @param array<string,string> $keys ContentLib key => Docs key
     */
    private function copy(array &$entry, array $data, array $keys): void
    {
        foreach ($keys as $key => $docsKey) {
            if (isset($data[$key])) {
                $entry[$docsKey] = (string) $data[$key];
            }
        }
    }

    private function counted(string $what): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + 1;
    }
}
