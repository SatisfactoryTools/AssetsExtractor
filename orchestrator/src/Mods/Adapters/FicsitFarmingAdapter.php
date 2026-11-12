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
     * The Seed Extractor makes every seed of a jar at once. Per minute, one machine takes 60 jars and gives these.
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
        $recipes = [];
        foreach (self::SEEDS as $jar => $seeds) {
            $seconds = self::cycle([self::JARS_PER_MINUTE, ...array_values($seeds)]);
            $className = sprintf('Recipe_SFT_FF_Seeds_From_%s_C', self::stem($jar));
            $entries->add('FGRecipe', [
                'ClassName' => $className,
                'mDisplayName' => 'Seeds (' . $entries->get($jar)['mDisplayName'] . ')',
                'mDisplayNameOverride' => 'True',
                'mIngredients' => $entries->amounts([$jar => intdiv(self::JARS_PER_MINUTE * $seconds, 60)]),
                'mProduct' => $entries->amounts(array_map(static fn(int $perMinute) => intdiv($perMinute * $seconds, 60), $seeds)),
                'mManufactoringDuration' => sprintf('%.6f', $seconds),
                'mProducedIn' => '("' . $entries->path(self::EXTRACTOR) . '")',
            ], self::PACKAGE);
            $recipes[] = $className;
        }
        $entries->unlockRecipes(self::SCHEMATIC, $recipes);
        $report->note(sprintf('added %d Seed Extractor recipes (one per jar, giving all its seeds, %d s cycle assumed), unlocked with the Seed Extractor',
            \count($recipes), self::CYCLE_SECONDS));
    }

    /**
     * The shortest cycle in which every per-minute rate is a whole amount.
     * @param list<int> $perMinute
     */
    private static function cycle(array $perMinute): int
    {
        for ($seconds = self::CYCLE_SECONDS; ; $seconds += self::CYCLE_SECONDS) {
            if (array_filter($perMinute, static fn(int $rate) => ($rate * $seconds) % 60 !== 0) === []) {
                return $seconds;
            }
        }
    }

    private static function stem(string $className): string
    {
        return (string) preg_replace(['/^Desc_FF_/', '/_C$/'], '', $className);
    }
}
