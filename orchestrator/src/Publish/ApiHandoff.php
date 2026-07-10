<?php

declare(strict_types=1);

namespace App\Publish;

use Symfony\Component\Process\Process;

/**
 * Component G: hands a finished build to the co-located API.
 *  1. Content-addressed images are already written into <api_data>/<images_subdir>
 *     by the Publisher (that dir is configured as the CDN dir).
 *  2. place(): copies each variant's data.json to
 *     <api_data>/<versions_subdir>/<branch>-<build><suffix>.json atomically (temp
 *     + rename), so the API never sees a partial file. The suffix distinguishes
 *     parser variants (e.g. "" for the default, "-ficsmas" for the ficsmas one).
 *  3. runImport(): runs the build-level API import command once, after all variant
 *     files are placed — <command> --buildId <b> --branch <br> [--version <v>].
 *
 * The import command is optional (empty = skip) so the file drop can be exercised
 * before the API command exists.
 */
final class ApiHandoff
{
    public function __construct(
        private readonly string $apiDataDir,
        private readonly string $versionsSubdir,
        private readonly string $command,
    ) {
    }

    /**
     * Atomically place a variant's client json at
     * <api_data>/<versions_subdir>/<branch>-<build><suffix>.json.
     *
     * @return string the path written to
     */
    public function place(string $dataJson, string $branch, string $buildId, string $suffix = ''): string
    {
        $versionsDir = rtrim($this->apiDataDir, '/') . '/' . trim($this->versionsSubdir, '/');
        if (!is_dir($versionsDir) && !mkdir($versionsDir, 0o775, true) && !is_dir($versionsDir)) {
            throw new \RuntimeException("Cannot create API versions dir: {$versionsDir}");
        }

        $dest = $versionsDir . '/' . $branch . '-' . $buildId . $suffix . '.json';
        $tmp = $dest . '.tmp';
        if (!copy($dataJson, $tmp) || !rename($tmp, $dest)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to place versioned data at {$dest}");
        }

        return $dest;
    }

    /**
     * Run the build-level API import command once (after all variant files are
     * placed): <command> --buildId <b> --branch <br> [--version <v>].
     * No-op if no command is configured.
     */
    public function runImport(string $branch, string $buildId, ?string $gameVersion = null): void
    {
        if ($this->command === '') {
            return;
        }
        $cmd = $this->command . ' --buildId ' . escapeshellarg($buildId) . ' --branch ' . escapeshellarg($branch);
        if ($gameVersion !== null && $gameVersion !== '') {
            $cmd .= ' --version ' . escapeshellarg($gameVersion);
        }
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(600.0);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "API publish command failed (exit {$process->getExitCode()}): "
                . substr($process->getErrorOutput() . $process->getOutput(), -1000)
            );
        }
    }
}
