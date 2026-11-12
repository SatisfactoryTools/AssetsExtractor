<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * The subset of npm-style semver ranges mods use in their dependencies:
 * ^, ~, >=, >, <=, <, =, x/* wildcards, space-separated AND and || OR.
 */
final class SemverRange
{
    public static function satisfies(string $version, string $range): bool
    {
        $range = trim($range);
        if ($range === '' || $range === '*') {
            return true;
        }

        foreach (explode('||', $range) as $alternative) {
            $comparators = preg_split('/\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $all = true;
            foreach ($comparators as $comparator) {
                if (!self::matches($version, $comparator)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    public static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = self::parse($a);
        [$coreB, $preB] = self::parse($b);
        $result = $coreA <=> $coreB;
        if ($result !== 0) {
            return $result;
        }
        if ($preA === $preB) {
            return 0;
        }
        if ($preA === '') {
            return 1;
        }
        if ($preB === '') {
            return -1;
        }

        return strcmp($preA, $preB) <=> 0;
    }

    private static function matches(string $version, string $comparator): bool
    {
        if (!preg_match('/^(\^|~|>=|<=|>|<|=)?v?(.*)$/', $comparator, $m)) {
            return false;
        }
        $operator = $m[1];
        $target = $m[2];

        if ($target === '' || $target === '*' || strtolower($target) === 'x') {
            return true;
        }

        $parts = explode('.', preg_replace('/[-+].*$/', '', $target) ?? $target);
        $wildcard = \count($parts) < 3 || \in_array(strtolower(end($parts)), ['x', '*'], true);
        $numbers = array_map(static fn(string $p) => ctype_digit($p) ? (int) $p : null, $parts);
        $fixed = [];
        foreach ($numbers as $number) {
            if ($number === null) {
                break;
            }
            $fixed[] = $number;
        }
        $lower = implode('.', array_pad($fixed, 3, 0)) . ($wildcard ? '' : self::prerelease($target));

        if (($operator === '' || $operator === '=') && $wildcard) {
            return self::compare($version, $lower) >= 0 && self::compare($version, self::bump($fixed, \count($fixed) - 1)) < 0;
        }

        return match ($operator) {
            '', '=' => self::compare($version, $lower) === 0,
            '>=' => self::compare($version, $lower) >= 0,
            '>' => self::compare($version, $lower) > 0,
            '<=' => self::compare($version, $lower) <= 0,
            '<' => self::compare($version, $lower) < 0,
            '~' => self::compare($version, $lower) >= 0
                && self::compare($version, self::bump($fixed, \count($fixed) >= 2 ? 1 : 0)) < 0,
            '^' => self::compare($version, $lower) >= 0
                && self::compare($version, self::bump($fixed, self::caretIndex($fixed))) < 0,
            default => false,
        };
    }

    /** @param list<int> $fixed */
    private static function caretIndex(array $fixed): int
    {
        foreach ($fixed as $i => $number) {
            if ($number !== 0) {
                return $i;
            }
        }

        return max(0, \count($fixed) - 1);
    }

    /** @param list<int> $fixed */
    private static function bump(array $fixed, int $index): string
    {
        if ($fixed === []) {
            return '999999.0.0';
        }
        $index = max(0, $index);
        $parts = array_slice(array_pad($fixed, 3, 0), 0, 3);
        $parts[$index]++;
        for ($i = $index + 1; $i < 3; $i++) {
            $parts[$i] = 0;
        }

        return implode('.', $parts) . '-0';
    }

    private static function prerelease(string $version): string
    {
        return preg_match('/-([^+]+)/', $version, $m) ? '-' . $m[1] : '';
    }

    /** @return array{0:list<int>,1:string} */
    private static function parse(string $version): array
    {
        $version = ltrim(trim($version), 'v');
        $pre = '';
        if (preg_match('/^([^-+]*)-([^+]+)/', $version, $m)) {
            $version = $m[1];
            $pre = $m[2];
        }
        $version = preg_replace('/\+.*$/', '', $version) ?? $version;
        $core = array_map('intval', array_pad(array_slice(explode('.', $version), 0, 3), 3, '0'));

        return [$core, $pre];
    }
}
