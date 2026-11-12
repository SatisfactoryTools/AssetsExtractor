<?php

declare(strict_types=1);

namespace App\Mods;

use SFTools\Data\Schema\DocsSchema;

/**
 * The part of a parsed data set a mod adds or changes: every keyed entry that is new
 * or different, and every list element that is new. Entries are kept whole because
 * custom versions replace entries by class name.
 */
final class ModDataDiff
{
    private const WORKBENCH = 'FGWorkBench';

    /**
     * @param array<string,mixed> $base data parsed without the mod
     * @param array<string,mixed> $withMod data parsed with the mod
     * @return array<string,mixed>
     */
    public static function diff(array $base, array $withMod): array
    {
        $result = [];
        foreach ($withMod as $category => $entries) {
            if (!\is_array($entries) || $entries === []) {
                continue;
            }
            $baseEntries = \is_array($base[$category] ?? null) ? $base[$category] : [];

            if (array_is_list($entries)) {
                $known = [];
                foreach ($baseEntries as $entry) {
                    $known[self::encode($entry)] = true;
                }
                $added = array_values(array_filter($entries, static fn(mixed $entry) => !isset($known[self::encode($entry)])));
                if ($added !== []) {
                    $result[$category] = $added;
                }
                continue;
            }

            $changed = [];
            foreach ($entries as $key => $entry) {
                if (!\array_key_exists($key, $baseEntries) || self::encode($baseEntries[$key]) !== self::encode($entry)) {
                    $changed[$key] = $entry;
                }
            }
            if ($changed !== []) {
                $result[$category] = $changed;
            }
        }

        return $result;
    }

    /**
     * Drops references the planner could not follow: buildings and items that do not
     * exist in the combined data, and the recipes that need them.
     * @param array<string,mixed> $diff
     * @param array<string,mixed> $withMod the full data parsed with the mod
     * @return array<string,mixed>
     */
    public static function clean(array $diff, array $withMod, ModReport $report): array
    {
        $buildings = (array) ($withMod['buildings'] ?? []);
        $items = (array) ($withMod['items'] ?? []);

        foreach ((array) ($diff['recipes'] ?? []) as $className => $recipe) {
            $missing = [];
            foreach ([...(array) ($recipe['ingredients'] ?? []), ...(array) ($recipe['products'] ?? [])] as $amount) {
                $item = (string) ($amount['item'] ?? '');
                if (!isset($items[$item]) && !isset($buildings[$item])) {
                    $missing[] = $item;
                }
            }
            if ($missing !== []) {
                unset($diff['recipes'][$className]);
                $report->skip((string) $className, 'uses unknown items: ' . implode(', ', array_unique($missing)));
                continue;
            }

            $producedIn = [];
            foreach ((array) ($recipe['producedIn'] ?? []) as $building) {
                if ($building === self::WORKBENCH) {
                    $diff['recipes'][$className]['inCraftBench'] = true;
                } elseif (isset($buildings[$building])) {
                    $producedIn[] = $building;
                } else {
                    $report->note("{$className} is made in {$building}, which was not imported; that building was removed from the recipe");
                }
            }
            $diff['recipes'][$className]['producedIn'] = $producedIn;
        }

        $recipes = (array) ($withMod['recipes'] ?? []);
        foreach (array_keys($report->skipped) as $skipped) {
            unset($recipes[$skipped]);
        }
        foreach ((array) ($diff['schematics'] ?? []) as $className => $schematic) {
            $unlocked = (array) ($schematic['unlock']['recipes'] ?? []);
            $kept = array_values(array_filter($unlocked, static fn(mixed $recipe) => isset($recipes[$recipe])));
            if (\count($kept) !== \count($unlocked)) {
                $diff['schematics'][$className]['unlock']['recipes'] = $kept;
            }
        }

        $diff = self::dropEquipmentStubs($diff);

        if (isset($diff['recipes']) && $diff['recipes'] === []) {
            unset($diff['recipes']);
        }

        return $diff;
    }

    /**
     * DocsParser makes an item for every class with an equipment slot, named after the base game's Equip_X →
     * BP_EquipmentDescriptorX convention; a mod's equipment classes follow no such convention, so they become
     * nameless items nothing uses.
     * @param array<string,mixed> $diff
     * @return array<string,mixed>
     */
    private static function dropEquipmentStubs(array $diff): array
    {
        $json = json_encode(array_diff_key($diff, ['items' => true]), JSON_THROW_ON_ERROR);
        foreach ((array) ($diff['items'] ?? []) as $className => $item) {
            if (($item['name'] ?? '') === '' && ($item['icon'] ?? null) === null && !str_contains($json, '"' . $className . '"')) {
                unset($diff['items'][$className]);
            }
        }

        return $diff;
    }

    /**
     * DocsParser stores these building values as whole numbers, which is fine for the
     * base game but cuts off the fractions mods use (an Assembler Mk.2 at 1.5×).
     * @param array<string,mixed> $diff
     * @param list<array{native:string,entry:array<string,mixed>}> $entries the mod's Docs entries
     * @return array<string,mixed>
     */
    public static function restorePrecision(array $diff, array $entries): array
    {
        $fields = ['manufacturingSpeed' => 'mManufacturingSpeed', 'powerUsage' => 'mPowerConsumption', 'powerProduction' => 'mPowerProduction'];
        foreach ($entries as $class) {
            $key = DocsSchema::convertBuildingClassName((string) ($class['entry']['ClassName'] ?? ''));
            if (!isset($diff['buildings'][$key])) {
                continue;
            }
            foreach ($fields as $field => $docsKey) {
                $value = $class['entry'][$docsKey] ?? null;
                if (is_numeric($value) && (float) $value !== floor((float) $value)) {
                    $diff['buildings'][$key][$field] = (float) $value;
                }
            }
        }

        return $diff;
    }

    /** @param array<string,mixed> $diff */
    public static function summarize(array $diff, ModReport $report): void
    {
        $report->result = [];
        foreach ($diff as $category => $entries) {
            if (\is_array($entries) && $entries !== []) {
                $report->result[(string) $category] = \count($entries);
            }
        }
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
