<?php

declare(strict_types=1);

namespace App\Publish;

/**
 * Rewrites every "Texture2D <assetPath>" icon reference in raw-export.json to its
 * content-hash id (Option 1 CDN scheme). The client then requests
 * <cdnBase>/256/<id>.png or /64/<id>.png. References whose asset failed extraction
 * (no id) become null. Produces the client-facing data.json.
 */
final class ReferenceRewriter
{
    private int $rewritten = 0;
    private int $unresolved = 0;

    /**
     * @param array<string,string> $idMap assetPath => id
     * @return array{rewritten:int,unresolved:int}
     */
    public function rewrite(string $rawExportPath, array $idMap, string $outPath): array
    {
        $this->rewritten = 0;
        $this->unresolved = 0;

        $data = json_decode((string) file_get_contents($rawExportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->walk($data, $idMap);

        // Client envelope: parsed data under "data", plus a "metadata" object the
        // API fills in later (empty for now).
        $output = ['data' => $data, 'metadata' => new \stdClass()];

        $dir = \dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create publish dir: {$dir}");
        }
        file_put_contents(
            $outPath,
            json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return ['rewritten' => $this->rewritten, 'unresolved' => $this->unresolved];
    }

    /**
     * @param array<string,string> $idMap
     */
    private function walk(mixed &$node, array $idMap): void
    {
        if (!\is_array($node)) {
            return;
        }
        foreach ($node as &$value) {
            if (\is_string($value) && str_starts_with($value, 'Texture2D ')) {
                $assetPath = trim(substr($value, \strlen('Texture2D ')));
                if (isset($idMap[$assetPath])) {
                    $value = $idMap[$assetPath];
                    $this->rewritten++;
                } else {
                    $value = null;
                    $this->unresolved++;
                }
            } elseif (\is_array($value)) {
                $this->walk($value, $idMap);
            }
        }
    }
}
