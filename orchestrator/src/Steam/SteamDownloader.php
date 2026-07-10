<?php

declare(strict_types=1);

namespace App\Steam;

use Symfony\Component\Process\Process;

/**
 * Downloads / delta-updates a Satisfactory branch into a persistent install dir
 * via steamcmd. Requires a Steam account that owns the game; the session must
 * have been established once interactively (`steamcmd +login <username>`) so no
 * password/2FA prompt is needed here.
 */
final class SteamDownloader
{
    public function __construct(
        private readonly string $steamcmdPath,
        private readonly int $appId,
        private readonly ?string $username,
    ) {
    }

    /**
     * Runs the steamcmd update. Returns true on a clean exit, or false when the
     * app installed correctly but steamcmd crashed in its cosmetic post-install
     * step (a Proton "install script evaluator" segfault that always happens for
     * Windows apps on headless Linux — harmless, the files are on disk).
     *
     * Success is judged by steamcmd's own appmanifest StateFlags, not the exit
     * code, precisely because of that post-step crash.
     *
     * @param callable(string):void|null $onProgress receives streamed output
     */
    public function update(string $installDir, string $betaKey, ?callable $onProgress = null): bool
    {
        if ($this->username === null || $this->username === '') {
            throw new \RuntimeException(
                'steam.username is not configured — a game-owning account is required to download.'
            );
        }

        if (!is_dir($installDir) && !mkdir($installDir, 0o775, true) && !is_dir($installDir)) {
            throw new \RuntimeException("Cannot create install dir: {$installDir}");
        }

        $args = [
            $this->steamcmdPath,
            '+force_install_dir', $installDir,
            '+login', $this->username,
            '+app_update', (string) $this->appId,
        ];
        // 'public' is the default branch; -beta is only needed for non-default branches.
        if ($betaKey !== '' && $betaKey !== 'public') {
            $args[] = '-beta';
            $args[] = $betaKey;
        }
        $args[] = 'validate';
        $args[] = '+quit';

        $process = new Process($args);
        $process->setTimeout(null);        // downloads can take a long time
        $process->setIdleTimeout(600.0);   // but fail if it stalls for 10 min

        $tail = '';
        $process->run(function (string $type, string $buffer) use ($onProgress, &$tail): void {
            $tail = substr($tail . $buffer, -4000);
            if ($onProgress !== null) {
                $onProgress($buffer);
            }
        });

        // Authoritative check: StateFlags == 4 (StateFullyInstalled).
        $installed = $this->isFullyInstalled($installDir);

        if (!$installed) {
            $hint = '';
            if (str_contains($tail, 'Login Failure') || str_contains($tail, 'Invalid Password')
                || str_contains($tail, 'Cached credentials not found')) {
                $hint = "\nNo cached session: run `steamcmd +login {$this->username}` once interactively.";
            }
            throw new \RuntimeException(
                "steamcmd app_update did not fully install branch '{$betaKey}' "
                . "(exit {$process->getExitCode()}, StateFlags != 4).{$hint}\n" . $tail
            );
        }

        return $process->isSuccessful();
    }

    /**
     * Reads <installDir>/steamapps/appmanifest_<appId>.acf and returns true if
     * StateFlags indicates "fully installed" (bit 0x4).
     */
    public function isFullyInstalled(string $installDir): bool
    {
        $acf = $installDir . '/steamapps/appmanifest_' . $this->appId . '.acf';
        if (!is_file($acf)) {
            return false;
        }
        $content = (string) file_get_contents($acf);
        if (!preg_match('/"StateFlags"\s+"(\d+)"/', $content, $m)) {
            return false;
        }

        return ((int) $m[1] & 0x4) === 0x4;
    }
}
