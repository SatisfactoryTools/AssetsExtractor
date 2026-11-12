<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * Default values of the game's C++ classes, which the paks do not contain. A blueprint
 * whose parent is a C++ class only stores the values it changes, so every Docs value of
 * such a blueprint that its class default object does not set is the C++ default.
 * The most common such value wins; a key no blueprint leaves alone falls back to the
 * most common value of the whole group.
 */
final class NativeDefaults
{
    private const IGNORED_KEYS = ['ClassName', 'FullName'];

    /** Values the Docs take from elsewhere (the sink point tables), so blueprints say nothing about their default. */
    private const FIXED_DEFAULTS = ['mResourceSinkPoints' => '0'];

    /**
     * @param array<string,array<string,mixed>> $classes native path => key => default
     * @param array<string,array<string,mixed>> $subobjects subobject class => key => default
     */
    private function __construct(
        private readonly array $classes,
        private readonly array $subobjects,
    ) {
    }

    /** @param list<array{className:string,super:?string,properties:array<string,mixed>}> $baseClasses */
    public static function infer(DocsFile $docs, array $baseClasses): self
    {
        $dumps = [];
        foreach ($baseClasses as $class) {
            $dumps[$class['className']] = $class;
        }

        $classes = [];
        $subVotes = [];
        $subFallback = [];
        foreach ($docs->groups() as $group) {
            $native = DocsFile::nativePath((string) $group['NativeClass']);
            $votes = [];
            $fallback = [];
            foreach ($group['Classes'] as $entry) {
                $dump = $dumps[(string) ($entry['ClassName'] ?? '')] ?? null;
                $direct = $dump !== null && $dump['super'] === $native;
                foreach ($entry as $key => $value) {
                    if (\in_array($key, self::IGNORED_KEYS, true)) {
                        continue;
                    }
                    self::vote($fallback, $key, $value);
                    if ($direct && !\array_key_exists($key, $dump['properties'])) {
                        self::vote($votes, $key, $value);
                    }
                    if (\is_array($value)) {
                        self::voteSubobjects($subVotes, $subFallback, $value, $dump !== null ? ($dump['properties'][$key] ?? null) : null);
                    }
                }
            }
            $classes[$native] = array_replace(self::winners($votes, $fallback), array_intersect_key(self::FIXED_DEFAULTS, $fallback));
        }

        $subobjects = [];
        foreach ($subFallback as $class => $fallback) {
            $subobjects[$class] = self::winners($subVotes[$class] ?? [], $fallback);
        }

        return new self($classes, $subobjects);
    }

    public function has(string $native): bool
    {
        return isset($this->classes[$native]);
    }

    /** @return array<string,mixed> */
    public function forClass(string $native): array
    {
        return $this->classes[$native] ?? [];
    }

    /** @return array<string,mixed> */
    public function forSubobject(string $class): array
    {
        return $this->subobjects[$class] ?? [];
    }

    /**
     * @param array<string,array<string,array<string,int>>> $votes
     * @param array<string,array<string,array<string,int>>> $fallback
     * @param list<mixed> $docsList
     */
    private static function voteSubobjects(array &$votes, array &$fallback, array $docsList, mixed $dumpList): void
    {
        foreach ($docsList as $i => $docsEntry) {
            if (!\is_array($docsEntry) || !isset($docsEntry['Class']) || !\is_string($docsEntry['Class'])) {
                continue;
            }
            $class = $docsEntry['Class'];
            $dumpEntry = \is_array($dumpList) && isset($dumpList[$i]['Class']) && $dumpList[$i]['Class'] === $class ? $dumpList[$i] : null;
            $fallback[$class] ??= [];
            foreach ($docsEntry as $key => $value) {
                if ($key === 'Class') {
                    continue;
                }
                self::vote($fallback[$class], $key, $value);
                if ($dumpEntry !== null && !\array_key_exists($key, $dumpEntry)) {
                    $votes[$class] ??= [];
                    self::vote($votes[$class], $key, $value);
                }
            }
        }
    }

    /** @param array<string,array<string,int>> $votes */
    private static function vote(array &$votes, string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $votes[$key][$encoded] = ($votes[$key][$encoded] ?? 0) + 1;
    }

    /**
     * @param array<string,array<string,int>> $votes
     * @param array<string,array<string,int>> $fallback
     * @return array<string,mixed>
     */
    private static function winners(array $votes, array $fallback): array
    {
        $result = [];
        foreach ($fallback as $key => $counts) {
            $pool = $votes[$key] ?? $counts;
            arsort($pool);
            $result[$key] = json_decode((string) array_key_first($pool), true);
        }

        return $result;
    }
}
