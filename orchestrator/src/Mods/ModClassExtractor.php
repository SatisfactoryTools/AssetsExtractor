<?php

declare(strict_types=1);

namespace App\Mods;

use Symfony\Component\Process\Process;

/**
 * Runs the .NET mod class extractor (mod-extractor-net), which dumps the blueprint
 * classes of mods or base-game packages with their class default values written the
 * way the game's Docs file writes them.
 */
final class ModClassExtractor
{
    public const SINK_TABLES = '^DT_ResourceSinkPoints';

    /** @param list<string> $engineCandidates */
    public function __construct(
        private readonly string $dotnet,
        private readonly string $dll,
        private readonly array $engineCandidates,
        private readonly string $aesKey,
    ) {
    }

    /**
     * @param list<array{reference:string,paksDir:string}> $mods
     * @param list<string> $packages base-game package paths, e.g. /Game/FactoryGame/Recipes/Constructor/Recipe_IronPlate
     * @param list<string> $classNames base-game class names to find by package name
     * @param string|null $baseTables regex of base-game data table package names to read too
     * @param list<string>|null $engineCandidates
     * @return array{engine:string,mods:list<array<string,mixed>>,base:?array<string,mixed>}
     */
    public function extract(string $installDir, string $workDir, array $mods, array $packages = [], array $classNames = [], ?string $baseTables = null, ?array $engineCandidates = null): array
    {
        if (!is_file($this->dll)) {
            throw new \RuntimeException("Mod extractor DLL not found: {$this->dll} — build mod-extractor-net (dotnet build -c Release).");
        }
        if (!is_dir($workDir) && !mkdir($workDir, 0o775, true) && !is_dir($workDir)) {
            throw new \RuntimeException("Cannot create {$workDir}");
        }

        $out = $workDir . '/classes.json';
        @unlink($out);
        $args = [
            $this->dotnet, $this->dll,
            '--install-dir', $installDir,
            '--out', $out,
            '--engine-candidates', implode(',', $engineCandidates ?? $this->engineCandidates),
        ];
        if ($mods !== []) {
            $modsFile = $workDir . '/mods.json';
            file_put_contents($modsFile, json_encode($mods, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            array_push($args, '--mods-file', $modsFile);
        }
        if ($packages !== []) {
            $packagesFile = $workDir . '/packages.json';
            file_put_contents($packagesFile, json_encode(array_values($packages), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            array_push($args, '--packages-file', $packagesFile);
        }
        if ($classNames !== []) {
            $classNamesFile = $workDir . '/class-names.json';
            file_put_contents($classNamesFile, json_encode(array_values($classNames), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            array_push($args, '--class-names-file', $classNamesFile);
        }
        if ($baseTables !== null) {
            array_push($args, '--base-tables', $baseTables);
        }
        if ($this->aesKey !== '') {
            array_push($args, '--aes-key', $this->aesKey);
        }

        $process = new Process($args);
        $process->setTimeout(1800.0);
        $process->run();
        if (!$process->isSuccessful() || !is_file($out)) {
            throw new \RuntimeException(
                "Mod class extraction failed (exit {$process->getExitCode()}).\n"
                . substr($process->getErrorOutput() . $process->getOutput(), -2000)
            );
        }

        /** @var array{engine:string,mods:list<array<string,mixed>>,base:?array<string,mixed>} $result */
        $result = json_decode((string) file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
