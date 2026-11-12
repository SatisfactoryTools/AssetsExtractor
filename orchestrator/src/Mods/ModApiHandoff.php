<?php

declare(strict_types=1);

namespace App\Mods;

use Symfony\Component\Process\Process;

/**
 * Hands an imported mod to the co-located API, whose `mods:import` command stores it
 * as a mod version (creating the mod on first import) owned by the configured user.
 */
final class ModApiHandoff
{
    public function __construct(private readonly string $command)
    {
    }

    public function enabled(): bool
    {
        return $this->command !== '';
    }

    /**
     * @param list<string> $dependsOn mod version ids of the dependencies
     * @return array{modId:string,modVersionId:string,createdMod:bool,createdVersion:bool}
     */
    public function import(string $dataFile, ModSource $source, bool $public, array $dependsOn, string $summaryFile): array
    {
        @unlink($summaryFile);
        $cmd = $this->command
            . ' --data ' . escapeshellarg($dataFile)
            . ' --reference ' . escapeshellarg($source->reference)
            . ' --name ' . escapeshellarg($source->name)
            . ' --mod-version ' . escapeshellarg($source->version)
            . ($source->sourceVersionId !== null ? ' --source-version ' . escapeshellarg($source->sourceVersionId) : '')
            . ($public ? ' --public' : '')
            . implode('', array_map(static fn(string $id) => ' --depends-on ' . escapeshellarg($id), $dependsOn))
            . ' --summary-file ' . escapeshellarg($summaryFile);

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(300.0);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "API mods:import failed for {$source->reference} (exit {$process->getExitCode()}): "
                . substr($process->getErrorOutput() . $process->getOutput(), -1500)
            );
        }

        $summary = json_decode((string) @file_get_contents($summaryFile), true);
        if (!\is_array($summary) || !isset($summary['modVersionId'])) {
            throw new \RuntimeException("API mods:import for {$source->reference} wrote no summary.");
        }

        /** @var array{modId:string,modVersionId:string,createdMod:bool,createdVersion:bool} $summary */
        return $summary;
    }
}
