<?php

declare(strict_types=1);

namespace App\Steam;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper around steamcmd for the read-only operations we need in the
 * version watcher. Fetching app info requires no credentials (anonymous login
 * works for the public branch metadata of app 526870).
 */
final class SteamClient
{
    public function __construct(
        private readonly string $steamcmdPath,
        private readonly int $appId,
    ) {
    }

    /**
     * Runs `steamcmd +app_info_print` and returns the raw stdout.
     */
    public function fetchAppInfoRaw(int $timeoutSeconds = 240): string
    {
        $process = new Process([
            $this->steamcmdPath,
            '+login', 'anonymous',
            '+app_info_update', '1',
            '+app_info_print', (string) $this->appId,
            '+quit',
        ]);
        $process->setTimeout((float) $timeoutSeconds);
        $process->run();

        $output = $process->getOutput();
        // steamcmd sometimes exits non-zero after a successful print; only fail
        // if we clearly got nothing usable.
        if (!str_contains($output, '"' . $this->appId . '"')) {
            throw new \RuntimeException(
                "steamcmd did not return app info for {$this->appId} (exit {$process->getExitCode()}).\n" .
                substr($process->getErrorOutput() . $output, -2000)
            );
        }

        return $output;
    }

    /**
     * Parsed build id per logical branch.
     *
     * @param array<string,string> $branchMap logical name => Steam beta key
     * @return array<string,?string> logical name => build id (null if branch absent)
     */
    public function getBranchBuildIds(array $branchMap, ?string $raw = null): array
    {
        $raw ??= $this->fetchAppInfoRaw();
        $tree = Vdf::parse($raw);

        /** @var array<string,mixed> $app */
        $app = $tree[(string) $this->appId] ?? [];
        /** @var array<string,mixed> $branches */
        $branches = $app['depots']['branches'] ?? [];

        $result = [];
        foreach ($branchMap as $logical => $betaKey) {
            $buildId = $branches[$betaKey]['buildid'] ?? null;
            $result[$logical] = \is_string($buildId) ? $buildId : null;
        }

        return $result;
    }
}
