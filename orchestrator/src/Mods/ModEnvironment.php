<?php

declare(strict_types=1);

namespace App\Mods;

use App\Config;
use App\Docs\DocsLocator;
use App\Docs\DocsParser;
use App\GameVersion;
use App\Images\ImageExtractor;

/**
 * The base game install a mod is read against, plus the tools that read it.
 */
final class ModEnvironment
{
    /** @param list<string> $engineCandidates */
    private function __construct(
        public readonly string $installDir,
        public readonly string $docsFile,
        public readonly ?GameVersion $gameVersion,
        public readonly array $engineCandidates,
        public readonly ModClassExtractor $classExtractor,
        public readonly ImageExtractor $imageExtractor,
        public readonly DocsParser $parser,
    ) {
    }

    public static function create(Config $config, string $installDir): self
    {
        if (!is_dir($installDir . '/FactoryGame/Content/Paks')) {
            throw new \RuntimeException("No game install at {$installDir}.");
        }

        $gameVersion = GameVersion::read($installDir);
        $configured = array_map('strval', (array) $config->get('extraction.engine_candidates', ['GAME_UE5_6']));
        $candidates = array_values(array_unique([...($gameVersion?->engine !== null ? [$gameVersion->engine] : []), ...$configured]));
        $dotnet = (string) $config->get('extraction.dotnet', 'dotnet');
        $aesKey = (string) $config->get('extraction.aes_key', '');

        return new self(
            installDir: $installDir,
            docsFile: (new DocsLocator((string) $config->get('docs.locale', 'en-US')))->locate($installDir),
            gameVersion: $gameVersion,
            engineCandidates: $candidates,
            classExtractor: new ModClassExtractor(
                $dotnet,
                (string) $config->get('extraction.mod_dll', \dirname(__DIR__, 3) . '/mod-extractor-net/bin/Release/net10.0/ModExtractor.dll'),
                $candidates,
                $aesKey,
            ),
            imageExtractor: new ImageExtractor(
                $dotnet,
                (string) $config->require('extraction.dll'),
                (string) $config->get('extraction.sizes', '256,64'),
                $candidates,
                $aesKey,
            ),
            parser: new DocsParser(),
        );
    }

    /**
     * Parses a Docs file the way published versions are parsed (without FICSMAS).
     * @return array<string,mixed>
     */
    public function parse(DocsFile $docs, string $dir): array
    {
        $docsPath = $dir . '/docs.json';
        $docs->write($docsPath);
        $rawExport = $this->parser->parse($docsPath, $dir . '/parsed', ['--no-ficsmas']);

        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents($rawExport), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
