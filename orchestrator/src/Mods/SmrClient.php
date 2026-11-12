<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * Read-only client for the ficsit.app mod repository (SMR). Mod data and downloads
 * are public, so no token is needed.
 */
final class SmrClient
{
    private const VERSION_FIELDS = 'id version game_version created_at targets { targetName link hash size } dependencies { mod_id condition optional }';

    public function __construct(
        private readonly string $apiUrl = 'https://api.ficsit.app',
        private readonly string $userAgent = 'sftools-mod-importer',
    ) {
    }

    /**
     * @return array{id:string,name:string,reference:string,latestRelease:?string,versions:list<array<string,mixed>>}|null
     */
    public function mod(string $reference): ?array
    {
        $query = 'query($ref: ModReference!) { getModByReference(modReference: $ref) { id name mod_reference '
            . 'latestVersions { release { id } } '
            . 'versions(filter: {limit: 100, order_by: created_at, order: desc}) { ' . self::VERSION_FIELDS . ' } } }';
        $data = $this->query($query, ['ref' => $reference]);
        $mod = $data['getModByReference'] ?? null;
        if (!\is_array($mod)) {
            return null;
        }

        return [
            'id' => (string) $mod['id'],
            'name' => (string) $mod['name'],
            'reference' => (string) $mod['mod_reference'],
            'latestRelease' => isset($mod['latestVersions']['release']['id']) ? (string) $mod['latestVersions']['release']['id'] : null,
            'versions' => array_values((array) ($mod['versions'] ?? [])),
        ];
    }

    /**
     * @param array<string,mixed> $version an entry of mod()['versions']
     * @return array{link:string,hash:?string}|null
     */
    public static function target(array $version, string $targetName = 'Windows'): ?array
    {
        foreach ((array) ($version['targets'] ?? []) as $target) {
            if (($target['targetName'] ?? null) === $targetName) {
                return ['link' => (string) $target['link'], 'hash' => isset($target['hash']) ? (string) $target['hash'] : null];
            }
        }

        return null;
    }

    public function download(string $link, string $destination, ?string $sha256 = null): void
    {
        if (is_file($destination) && ($sha256 === null || hash_file('sha256', $destination) === $sha256)) {
            return;
        }

        $dir = \dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create download dir: {$dir}");
        }

        $url = str_starts_with($link, 'http') ? $link : rtrim($this->apiUrl, '/') . $link;
        $tmp = $destination . '.part';
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => 1800,
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        fclose($handle);

        if ($ok !== true) {
            @unlink($tmp);
            throw new \RuntimeException("Download of {$url} failed: {$error}");
        }
        if ($sha256 !== null && hash_file('sha256', $tmp) !== $sha256) {
            @unlink($tmp);
            throw new \RuntimeException("Download of {$url} does not match the hash ficsit.app lists.");
        }
        rename($tmp, $destination);
    }

    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private function query(string $query, array $variables): array
    {
        $curl = curl_init(rtrim($this->apiUrl, '/') . '/v2/query');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($curl);
        if (!\is_string($body)) {
            throw new \RuntimeException('ficsit.app request failed: ' . curl_error($curl));
        }

        $json = json_decode($body, true);
        if (!\is_array($json)) {
            throw new \RuntimeException('ficsit.app returned invalid JSON.');
        }
        if (isset($json['errors'][0]['message'])) {
            throw new \RuntimeException('ficsit.app: ' . $json['errors'][0]['message']);
        }

        return (array) ($json['data'] ?? []);
    }
}
