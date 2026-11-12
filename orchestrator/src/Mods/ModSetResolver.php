<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * Collects a mod and everything it depends on, dependencies first. Mods come from
 * local .smod files when given, otherwise from ficsit.app.
 */
final class ModSetResolver
{
    /** Mods that never carry game content (the mod loader itself). */
    private const IGNORED = ['SML', 'FactoryGame'];

    /** @var array<string,ModSource> */
    private array $resolved = [];

    /** @var array<string,true> */
    private array $visiting = [];

    /**
     * @param array<string,SmodPackage> $local reference => package unpacked from a local file
     * @param callable(string):void $log
     */
    public function __construct(
        private readonly SmrClient $smr,
        private readonly string $downloadDir,
        private readonly string $unpackDir,
        private readonly array $local,
        private readonly mixed $log,
    ) {
    }

    /** @return array<string,ModSource> reference => source, dependencies before the mods that need them */
    public function resolve(string $reference, ?string $version): array
    {
        $this->resolved = [];
        $this->visiting = [];
        $this->visit($reference, $version, null);

        return $this->resolved;
    }

    private function visit(string $reference, ?string $version, ?string $condition): void
    {
        if (isset($this->resolved[$reference]) || \in_array($reference, self::IGNORED, true)) {
            return;
        }
        if (isset($this->visiting[$reference])) {
            throw new \RuntimeException("Mods depend on each other in a loop through {$reference}.");
        }
        $this->visiting[$reference] = true;

        $source = isset($this->local[$reference])
            ? $this->fromLocal($this->local[$reference])
            : $this->fromSmr($reference, $version, $condition);

        foreach ($source['dependencies'] as $dependency) {
            $this->visit($dependency['reference'], null, $dependency['condition']);
        }

        unset($this->visiting[$reference]);
        $this->resolved[$reference] = new ModSource(
            $source['package'],
            $reference,
            $source['name'],
            $source['version'],
            $source['sourceVersionId'],
            array_values(array_filter(
                array_map(static fn(array $d) => $d['reference'], $source['dependencies']),
                static fn(string $d) => !\in_array($d, self::IGNORED, true),
            )),
        );
    }

    /** @return array{package:SmodPackage,name:string,version:string,sourceVersionId:?string,dependencies:list<array{reference:string,condition:string}>} */
    private function fromLocal(SmodPackage $package): array
    {
        $sourceVersionId = null;
        $name = $package->friendlyName;
        try {
            $mod = $this->smr->mod($package->reference);
            if ($mod !== null) {
                $name = $mod['name'];
                foreach ($mod['versions'] as $candidate) {
                    if (($candidate['version'] ?? null) === $package->version) {
                        $sourceVersionId = (string) $candidate['id'];
                    }
                }
            }
        } catch (\Throwable) {
            // A local file does not need ficsit.app; it only adds the name and link.
        }

        return [
            'package' => $package,
            'name' => $name,
            'version' => $package->version,
            'sourceVersionId' => $sourceVersionId,
            'dependencies' => array_values(array_filter($package->dependencies, static fn(array $d) => !$d['optional'])),
        ];
    }

    /** @return array{package:SmodPackage,name:string,version:string,sourceVersionId:?string,dependencies:list<array{reference:string,condition:string}>} */
    private function fromSmr(string $reference, ?string $version, ?string $condition): array
    {
        $mod = $this->smr->mod($reference);
        if ($mod === null) {
            throw new \RuntimeException("ficsit.app has no mod called {$reference}.");
        }

        $chosen = $this->chooseVersion($mod, $version, $condition);
        $target = SmrClient::target($chosen);
        if ($target === null) {
            throw new \RuntimeException("{$reference} {$chosen['version']} has no Windows download on ficsit.app.");
        }

        ($this->log)(sprintf('Downloading %s %s…', $reference, $chosen['version']));
        $file = $this->downloadDir . '/' . $reference . '/' . $chosen['version'] . '.smod';
        $this->smr->download($target['link'], $file, $target['hash']);
        $package = SmodPackage::extract($file, $this->unpackDir . '/' . $reference);

        $dependencies = [];
        foreach ((array) ($chosen['dependencies'] ?? []) as $dependency) {
            if (($dependency['optional'] ?? false) === true) {
                continue;
            }
            $dependencies[] = ['reference' => (string) $dependency['mod_id'], 'condition' => (string) ($dependency['condition'] ?? '*')];
        }

        return [
            'package' => $package,
            'name' => $mod['name'],
            'version' => (string) $chosen['version'],
            'sourceVersionId' => (string) $chosen['id'],
            'dependencies' => $dependencies,
        ];
    }

    /**
     * @param array{latestRelease:?string,versions:list<array<string,mixed>>,reference:string} $mod
     * @return array<string,mixed>
     */
    private function chooseVersion(array $mod, ?string $version, ?string $condition): array
    {
        $versions = $mod['versions'];
        if ($versions === []) {
            throw new \RuntimeException("{$mod['reference']} has no versions on ficsit.app.");
        }

        if ($version !== null) {
            foreach ($versions as $candidate) {
                if ($candidate['version'] === $version || $candidate['id'] === $version) {
                    return $candidate;
                }
            }
            throw new \RuntimeException("{$mod['reference']} has no version {$version} on ficsit.app.");
        }

        if ($condition !== null) {
            $matching = array_values(array_filter($versions, static fn(array $v) => SemverRange::satisfies((string) $v['version'], $condition)));
            usort($matching, static fn(array $a, array $b) => SemverRange::compare((string) $b['version'], (string) $a['version']));
            if ($matching !== []) {
                return $matching[0];
            }
            ($this->log)("No {$mod['reference']} version matches {$condition}; using the latest one.");
        }

        foreach ($versions as $candidate) {
            if ($candidate['id'] === $mod['latestRelease']) {
                return $candidate;
            }
        }

        return $versions[0];
    }
}
