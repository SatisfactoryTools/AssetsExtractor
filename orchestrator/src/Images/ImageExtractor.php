<?php

declare(strict_types=1);

namespace App\Images;

use Symfony\Component\Process\Process;

/**
 * Component E/F: invokes the .NET image extractor (CUE4Parse + SkiaSharp) over
 * the image manifest, producing normalized PNGs and an extraction-result.json.
 */
final class ImageExtractor
{
    public const RESULT_FILE = 'extraction-result.json';

    /**
     * @param list<string> $engineCandidates
     */
    public function __construct(
        private readonly string $dotnet,
        private readonly string $dll,
        private readonly string $sizes,
        private readonly array $engineCandidates,
        private readonly string $aesKey,
    ) {
    }

    /**
     * @param list<string>|null $engineCandidates ordered override; the extractor
     *        tries them in order and picks the first that parses the paks. Pass the
     *        engine read from the game's version file first to "pin" it, keeping the
     *        configured candidates as fallback. Null uses the configured list.
     * @return array{total:int,ok:int,failed:int,engine:string,resultPath:string,assets:array<int,mixed>}
     */
    public function extract(
        string $manifestPath,
        string $installDir,
        string $outDir,
        ?callable $onProgress = null,
        ?array $engineCandidates = null,
    ): array {
        if (!is_file($this->dll)) {
            throw new \RuntimeException("Extractor DLL not found: {$this->dll} — build extractor-net (dotnet build -c Release).");
        }

        $args = [
            $this->dotnet, $this->dll,
            '--manifest', $manifestPath,
            '--install-dir', $installDir,
            '--out', $outDir,
            '--sizes', $this->sizes,
            '--engine-candidates', implode(',', $engineCandidates ?? $this->engineCandidates),
        ];
        if ($this->aesKey !== '') {
            $args[] = '--aes-key';
            $args[] = $this->aesKey;
        }

        $process = new Process($args);
        $process->setTimeout(1800.0);
        $process->run(function (string $type, string $buffer) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($buffer);
            }
        });

        $resultPath = $outDir . '/' . self::RESULT_FILE;
        if (!is_file($resultPath)) {
            throw new \RuntimeException(
                "Image extractor produced no result (exit {$process->getExitCode()}).\n" .
                substr($process->getErrorOutput() . $process->getOutput(), -2000)
            );
        }

        /** @var array{total:int,ok:int,failed:int,engine:string,assets:array<int,mixed>} $result */
        $result = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
        $result['resultPath'] = $resultPath;

        return $result;
    }
}
