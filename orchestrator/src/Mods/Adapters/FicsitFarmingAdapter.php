<?php

declare(strict_types=1);

namespace App\Mods\Adapters;

use App\Mods\ModEntries;
use App\Mods\ModReport;

final class FicsitFarmingAdapter extends ModAdapter
{
    private const EXTRACTOR = 'Build_FF_SeedExtractor_C';

    /** The schematic that unlocks the Seed Extractor. */
    private const SCHEMATIC = 'Schematic_FF_Tier2_1_C';

    private const PACKAGE = '/FicsitFarming/SFTools/SeedExtractor';

    /** Assumed; the real cycle is set in the mod's code. */
    private const CYCLE_SECONDS = 1;

    /**
     * The Seed Extractor picks seeds in code. Per minute, one machine takes 60 jars and gives
     * these rates; each pair becomes its own recipe.
     * @var array<string,array<string,int>> jar => seed => seeds per minute
     */
    private const SEEDS = [
        'Desc_FF_Dirt_C' => [
            'Desc_FF_CarrotSeed_C' => 60,
            'Desc_FF_CornSeed_C' => 60,
            'Desc_FF_PotatoSeed_C' => 60,
            'Desc_FF_WheatSeed_C' => 60,
        ],
        'Desc_FF_Dirt_Fertilized_C' => [
            'Desc_FF_AppleSeed_C' => 60,
            'Desc_FF_BananaSeed_C' => 60,
            'Desc_FF_YosseSeed_C' => 60,
        ],
        'Desc_FF_Dirt_Wet_C' => [
            'Desc_FF_CottonSeed_C' => 30,
            'Desc_FF_MyceliaSeed_C' => 30,
            'Desc_FF_PumpkinSeed_C' => 60,
            'Desc_FF_YosseSeed_C' => 60,
        ],
    ];

    private const JARS_PER_MINUTE = 60;

    public function reference(): string
    {
        return 'FicsitFarming';
    }

    public function since(): string
    {
        return '2026.3.18';
    }

    public function patchEntries(ModEntries $entries, ModReport $report): void
    {
        $sources = [];
        foreach (self::SEEDS as $seeds) {
            foreach (array_keys($seeds) as $seed) {
                $sources[$seed] = ($sources[$seed] ?? 0) + 1;
            }
        }

        $recipes = [];
        foreach (self::SEEDS as $jar => $seeds) {
            foreach ($seeds as $seed => $perMinute) {
                [$jars, $made, $seconds] = self::cycle(self::JARS_PER_MINUTE, $perMinute);
                $className = sprintf('Recipe_SFT_FF_%s_From_%s_C', self::stem($seed), self::stem($jar));
                $name = (string) $entries->get($seed)['mDisplayName'];
                if ($sources[$seed] > 1) {
                    $name .= ' (' . $entries->get($jar)['mDisplayName'] . ')';
                }
                $entries->add('FGRecipe', [
                    'ClassName' => $className,
                    'mDisplayName' => $name,
                    'mIngredients' => $entries->amounts([$jar => $jars]),
                    'mProduct' => $entries->amounts([$seed => $made]),
                    'mManufactoringDuration' => sprintf('%.6f', $seconds),
                    'mProducedIn' => '("' . $entries->path(self::EXTRACTOR) . '")',
                ], self::PACKAGE);
                $recipes[] = $className;
            }
        }
        $entries->unlockRecipes(self::SCHEMATIC, $recipes);
        $report->note(sprintf('added %d Seed Extractor recipes (one per jar and seed, %d s cycle assumed), unlocked with the Seed Extractor',
            \count($recipes), self::CYCLE_SECONDS));
    }

    /**
     * Whole amounts per cycle for the given rates, lengthening the cycle where needed.
     * @return array{int,int,int} jars, seeds, seconds
     */
    private static function cycle(int $jarsPerMinute, int $seedsPerMinute): array
    {
        for ($seconds = self::CYCLE_SECONDS; ; $seconds += self::CYCLE_SECONDS) {
            if (($jarsPerMinute * $seconds) % 60 === 0 && ($seedsPerMinute * $seconds) % 60 === 0) {
                return [intdiv($jarsPerMinute * $seconds, 60), intdiv($seedsPerMinute * $seconds, 60), $seconds];
            }
        }
    }

    private static function stem(string $className): string
    {
        return (string) preg_replace(['/^Desc_FF_/', '/_C$/'], '', $className);
    }
}
