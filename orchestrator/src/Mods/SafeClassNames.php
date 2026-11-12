<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * DocsParser's tokenizer only reads letters, digits, "_" and "-" in class names and paths, so a mod class like
 * "Build_Extractor_SF+_C" or "Recipe_Foo™_C" fails the whole parse. Names and every path that refers to them are
 * converted the same way, which keeps them matching across mods.
 */
final class SafeClassNames
{
    /** Item icons are read as plain strings and must keep the real path for the image extractor. */
    private const TEXT_KEYS = ['mDisplayName', 'mDescription', 'mAbbreviatedDisplayName', 'mSmallIcon', 'mPersistentBigIcon'];

    /**
     * @param list<array{native:string,entry:array<string,mixed>}> $entries
     * @return list<array{native:string,entry:array<string,mixed>}>
     */
    public static function apply(array $entries, ModReport $report): array
    {
        $renamed = [];
        foreach ($entries as &$item) {
            array_walk_recursive($item['entry'], static function (mixed &$value, string|int $key) use (&$renamed): void {
                if (!\is_string($value) || \in_array($key, self::TEXT_KEYS, true) || str_starts_with($value, 'Texture2D ')) {
                    return;
                }
                $safe = $key === 'ClassName' ? self::name($value) : self::paths($value);
                if ($safe === $value) {
                    return;
                }
                if ($key === 'ClassName') {
                    $renamed[$value] = $safe;
                }
                $value = $safe;
            });
        }
        unset($item);

        foreach ($renamed as $old => $new) {
            $report->note("{$old} was renamed to {$new}, because the Docs parser only reads letters, digits, _ and - in class names");
        }

        return $entries;
    }

    /**
     * @param array<string,array{native:string,entry:array<string,mixed>}> $entries class name => entry
     * @return array<string,array{native:string,entry:array<string,mixed>}>
     */
    public static function applyKeyed(array $entries, ModReport $report): array
    {
        $result = [];
        foreach (self::apply(array_values($entries), $report) as $entry) {
            $result[(string) $entry['entry']['ClassName']] = $entry;
        }

        return $result;
    }

    private static function name(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', self::ascii($value));
    }

    private static function ascii(string $value): string
    {
        return (string) preg_replace_callback('/[^\x00-\x7F]+/u', static function (array $match): string {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $match[0]); // @ intentional
            $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', \is_string($transliterated) ? $transliterated : '');

            return $clean === '' || $clean === null ? '_' : $clean;
        }, $value);
    }

    private static function paths(string $value): string
    {
        $value = (string) preg_replace_callback('#/[^"\'(),=]+#u', static fn(array $match): string => implode('', array_map(
            static fn(string $part) => \in_array($part, ['/', '.', ':'], true) ? $part : self::name($part),
            preg_split('#([/.:])#', $match[0], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [],
        )), $value);

        // Anything else outside ASCII, outside a path, still stops the tokenizer.
        return self::ascii($value);
    }
}
