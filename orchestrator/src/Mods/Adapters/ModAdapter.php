<?php

declare(strict_types=1);

namespace App\Mods\Adapters;

use App\Mods\ModEntries;
use App\Mods\ModReport;

/**
 * What the importer cannot read from one mod's files, written by hand: values its C++
 * code sets, content it creates at runtime, fixes for its data. An adapter is written
 * against one mod version and used for that version and every later one.
 */
abstract class ModAdapter
{
    /** ficsit.app mod reference */
    abstract public function reference(): string;

    /** The mod version this adapter was written for. */
    abstract public function since(): string;

    /**
     * Types of classes built on the mod's own code, like --class-map (which wins over these).
     * @return array<string,string> blueprint or C++ class name => native class (FGRecipe or /Script/FactoryGame.FGRecipe)
     */
    public function classMap(): array
    {
        return [];
    }

    /** Changes to the mod's Docs entries before they are parsed. */
    public function patchEntries(ModEntries $entries, ModReport $report): void
    {
    }

    /**
     * Changes to the parsed data, for what Docs entries cannot express.
     * @param array<string,mixed> $data
     */
    public function patchData(array &$data, ModReport $report): void
    {
    }
}
