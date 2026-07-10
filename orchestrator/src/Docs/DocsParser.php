<?php

declare(strict_types=1);

namespace App\Docs;

use Symfony\Component\Process\Process;

/**
 * Component D: parses a Docs locale file into raw-export.json by invoking the
 * satisfactory-tools/docs-parser console command (its canonical entry point):
 *
 *   docsParser parse -o <outDir> --enrich [flags...] <docsFile>
 *
 * Running the command — rather than calling the library — keeps the parse
 * pipeline (which transformers run, in what order) owned by the package, so our
 * side can't drift from it. It also matches how the orchestrator drives its
 * other external tools (steamcmd, the .NET extractor).
 */
final class DocsParser
{
    public const OUTPUT_FILE = 'raw-export.json';

    private readonly string $binary;

    public function __construct(?string $binary = null)
    {
        // The package's console bin, installed into our own vendor/bin.
        $this->binary = $binary ?? \dirname(__DIR__, 2) . '/vendor/bin/docsParser';
    }

    /**
     * @param list<string> $flags extra parser flags (e.g. ['--no-ficsmas'])
     * @return string absolute path to the produced raw-export.json
     */
    public function parse(string $docsFile, string $outDir, array $flags = []): string
    {
        if (!is_file($this->binary)) {
            throw new \RuntimeException(
                "Parser binary not found: {$this->binary} — run `composer install` in orchestrator/."
            );
        }
        if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Cannot create parser output dir: {$outDir}");
        }

        $process = new Process([
            PHP_BINARY, $this->binary,
            'parse',
            '-o', $outDir,
            '--enrich',
            ...array_map('strval', $flags),
            $docsFile,
        ]);
        $process->setTimeout(300.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Docs parser failed (exit {$process->getExitCode()}).\n"
                . $process->getErrorOutput() . $process->getOutput()
            );
        }

        $output = $outDir . '/' . self::OUTPUT_FILE;
        if (!is_file($output)) {
            throw new \RuntimeException("Parser did not produce {$output}");
        }

        return $output;
    }
}
