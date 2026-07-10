<?php

declare(strict_types=1);

namespace App;

/**
 * Reads the human game version + UE engine version from the loose version file
 * shipped in the install (Engine/Binaries/Win64/*-Win64-Shipping.version), e.g.
 * GameVersion "1.2.3.1", BranchName "++FactoryGame+rel-main-1.2.0", MajorVersion
 * 5 / MinorVersion 6 -> engine "GAME_UE5_6". Returns null if unreadable so the
 * pipeline can fall back to build id + engine auto-detection.
 */
final class GameVersion
{
    public function __construct(
        public readonly string $gameVersion,
        public readonly ?int $changelist,
        public readonly ?string $branchName,
        public readonly ?string $engine,
    ) {
    }

    public static function read(string $installDir): ?self
    {
        $dir = rtrim($installDir, '/') . '/Engine/Binaries/Win64';
        $candidates = glob($dir . '/*-Win64-Shipping.version') ?: [];
        // Prefer the FactoryGame* module's version file.
        usort($candidates, static fn(string $a, string $b) =>
            (int) str_contains($b, 'FactoryGame') <=> (int) str_contains($a, 'FactoryGame'));

        foreach ($candidates as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!\is_array($data) || !isset($data['GameVersion'])) {
                continue;
            }
            $engine = isset($data['MajorVersion'], $data['MinorVersion'])
                ? sprintf('GAME_UE%d_%d', $data['MajorVersion'], $data['MinorVersion'])
                : null;

            return new self(
                (string) $data['GameVersion'],
                isset($data['Changelist']) ? (int) $data['Changelist'] : null,
                isset($data['BranchName']) ? (string) $data['BranchName'] : null,
                $engine,
            );
        }

        return null;
    }
}
