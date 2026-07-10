<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-branch advisory file lock so two runs of the same branch never overlap,
 * while different branches may run concurrently. Non-blocking: acquire()
 * returns false immediately if another process holds the lock.
 */
final class RunLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $lockFile)
    {
    }

    public function acquire(): bool
    {
        $dir = \dirname($this->lockFile);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create lock directory: {$dir}");
        }

        $handle = fopen($this->lockFile, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
