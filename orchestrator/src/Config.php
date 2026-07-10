<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and exposes the YAML configuration. Values are read with dotted paths,
 * e.g. $config->get('steam.app_id').
 */
final class Config
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data, public readonly string $path)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= getenv('EXTRACTOR_CONFIG') ?: \dirname(__DIR__) . '/config/config.yaml';
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Config file not found: {$path}\n" .
                "Copy config/config.example.yaml to config/config.yaml and fill it in."
            );
        }

        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \RuntimeException("Config file {$path} did not parse to a mapping.");
        }

        return new self($data, $path);
    }

    public function get(string $dottedKey, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $dottedKey) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function require(string $dottedKey): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($dottedKey, $sentinel);
        if ($value === $sentinel) {
            throw new \RuntimeException("Missing required config key '{$dottedKey}' in {$this->path}");
        }

        return $value;
    }

    /** @return array<string,string> logical branch name => Steam beta key */
    public function branches(): array
    {
        /** @var array<string,string> $branches */
        $branches = $this->get('steam.branches', []);

        return $branches;
    }

    public function dataPath(string ...$parts): string
    {
        $root = rtrim((string) $this->require('paths.data'), '/');

        return $parts ? $root . '/' . implode('/', $parts) : $root;
    }
}
