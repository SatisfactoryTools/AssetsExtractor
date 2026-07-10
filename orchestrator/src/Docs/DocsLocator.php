<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Finds the Docs file inside a game install and hashes it. The current game
 * ships per-locale files at CommunityResources/Docs/<locale>.json; older builds
 * shipped a single CommunityResources/Docs.json (handled as a fallback).
 */
final class DocsLocator
{
    public function __construct(private readonly string $locale = 'en-US')
    {
    }

    public function locate(string $installDir): string
    {
        $candidates = [
            $installDir . '/CommunityResources/Docs/' . $this->locale . '.json',
            $installDir . '/CommunityResources/Docs.json', // legacy single-file
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException(
            "Docs file not found for locale '{$this->locale}' under {$installDir}/CommunityResources"
        );
    }

    public function hash(string $docsFile): string
    {
        $hash = hash_file('sha256', $docsFile);
        if ($hash === false) {
            throw new \RuntimeException("Cannot hash docs file: {$docsFile}");
        }

        return $hash;
    }
}
