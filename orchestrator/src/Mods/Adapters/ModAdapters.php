<?php

declare(strict_types=1);

namespace App\Mods\Adapters;

use App\Mods\ModReport;
use App\Mods\ModSource;
use App\Mods\SemverRange;

final class ModAdapters
{
    /** @var array<string,ModAdapter> reference => adapter */
    private array $adapters = [];

    public function __construct()
    {
        foreach ([new FicsitFarmingAdapter()] as $adapter) {
            $this->adapters[$adapter->reference()] = $adapter;
        }
    }

    /** The adapter for this mod version; none for a version older than the adapter. */
    public function for(ModSource $source): ?ModAdapter
    {
        $adapter = $this->adapters[$source->reference] ?? null;

        return $adapter !== null && SemverRange::compare($source->version, $adapter->since()) >= 0 ? $adapter : null;
    }

    public function describe(ModSource $source, ModReport $report): void
    {
        $adapter = $this->adapters[$source->reference] ?? null;
        if ($adapter === null) {
            return;
        }
        $name = (new \ReflectionClass($adapter))->getShortName();
        if ($this->for($source) === null) {
            $report->note("{$name} is written for {$adapter->since()} and later, so it is not used for {$source->version}");

            return;
        }
        $report->adapter = "{$name} (written for {$adapter->since()})";
    }
}
