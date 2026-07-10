<?php

declare(strict_types=1);

namespace App\Publish;

use App\State\StateStore;

/**
 * Phase 5/6 (image side): turns the extractor's per-asset output into a
 * content-addressed CDN image set.
 *
 * Each icon gets an id = the first N hex chars of its 256px content hash. Files
 * are published as <cdn>/256/<id>.png and <cdn>/64/<id>.png, so identical images
 * (incl. smallIcon == bigIcon and icons shared across items) collapse to one id
 * and one file pair. The `cdn_image` table tracks which ids already exist so we
 * never re-copy them.
 */
final class Publisher
{
    public function __construct(
        private readonly StateStore $store,
        private readonly int $idLength = 16,
    ) {
    }

    /**
     * @param array{assets:array<int,array<string,mixed>>} $extractionResult
     * @return array{idMap:array<string,string>, unique:int, new:int, copied:int, failedAssets:int}
     */
    public function publish(array $extractionResult, string $imagesDir, string $cdnDir, ?string $build): array
    {
        /** @var array<string,string> $idMap assetPath => id */
        $idMap = [];
        /** @var array<string,array{sha256:string,files:array<string,string>}> $byId */
        $byId = [];
        $failedAssets = 0;

        foreach ($extractionResult['assets'] as $asset) {
            if (!($asset['ok'] ?? false)) {
                $failedAssets++;
                continue;
            }
            $sizes = $asset['sizes'] ?? [];
            $canonical = $sizes['256']['sha256'] ?? null;
            if (!\is_string($canonical)) {
                $failedAssets++;
                continue;
            }

            $id = substr($canonical, 0, $this->idLength);
            $idMap[(string) $asset['assetPath']] = $id;

            if (isset($byId[$id])) {
                // Same short id, different content = truncated-hash collision.
                if ($byId[$id]['sha256'] !== $canonical) {
                    throw new \RuntimeException(self::collisionMessage($id));
                }
            } else {
                $files = [];
                foreach ($sizes as $size => $info) {
                    $files[(string) $size] = $imagesDir . '/' . $info['path'];
                }
                $byId[$id] = ['sha256' => $canonical, 'files' => $files];
            }
        }

        $ids = array_keys($byId);
        $existing = $this->store->getExistingImageIds($ids);

        // A previously-published id with a different full hash is also a collision.
        foreach ($byId as $id => $data) {
            if (isset($existing[$id]) && $existing[$id] !== $data['sha256']) {
                throw new \RuntimeException(self::collisionMessage($id));
            }
        }

        // Copy every id's files into the CDN layout if not already on disk
        // (idempotent — re-copies if the destination was wiped), and collect the
        // ids that are new to the DB.
        $newRows = [];
        $copied = 0;
        foreach ($byId as $id => $data) {
            foreach ($data['files'] as $size => $srcPath) {
                $destDir = $cdnDir . '/' . $size;
                if (!is_dir($destDir) && !mkdir($destDir, 0o775, true) && !is_dir($destDir)) {
                    throw new \RuntimeException("Cannot create CDN dir: {$destDir}");
                }
                $dest = $destDir . '/' . $id . '.png';
                if (!is_file($dest)) {
                    if (!copy($srcPath, $dest)) {
                        throw new \RuntimeException("Failed to copy {$srcPath} -> {$dest}");
                    }
                    $copied++;
                }
            }
            if (!isset($existing[$id])) {
                $newRows[] = ['id' => $id, 'sha256' => $data['sha256'], 'build' => $build];
            }
        }

        $this->store->recordImages($newRows);

        return [
            'idMap' => $idMap,
            'unique' => \count($byId),
            'new' => \count($newRows),
            'copied' => $copied,
            'failedAssets' => $failedAssets,
        ];
    }

    private static function collisionMessage(string $id): string
    {
        return "Content-hash id collision on '{$id}' — two distinct images share this "
            . 'short id. Increase publish.id_length and re-run.';
    }
}
