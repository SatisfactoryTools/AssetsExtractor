<?php

declare(strict_types=1);

namespace App\Steam;

/**
 * Minimal parser for Valve KeyValues (VDF) text, as emitted by
 * `steamcmd +app_info_print`. Only supports the subset we need:
 * quoted keys/values and nested `{ }` objects. Any non-token text
 * (steamcmd banners, progress lines) before/around the block is ignored,
 * so the raw steamcmd stdout can be passed in directly.
 */
final class Vdf
{
    /**
     * @return array<string,mixed> nested map; scalar leaves are strings
     */
    public static function parse(string $text): array
    {
        $tokens = self::tokenize($text);
        $pos = 0;

        return self::parseObject($tokens, $pos);
    }

    /**
     * @param list<array{0:string,1?:string}> $tokens
     * @return array<string,mixed>
     */
    private static function parseObject(array $tokens, int &$pos): array
    {
        $result = [];
        $count = \count($tokens);

        while ($pos < $count) {
            [$type] = $tokens[$pos];

            if ($type === '}') {
                $pos++;
                break;
            }

            if ($type !== 'str') { // stray '{' at this level — skip defensively
                $pos++;
                continue;
            }

            $key = $tokens[$pos][1] ?? '';
            $pos++;

            if ($pos >= $count) {
                break;
            }

            [$nextType] = $tokens[$pos];
            if ($nextType === '{') {
                $pos++;
                $result[$key] = self::parseObject($tokens, $pos);
            } elseif ($nextType === 'str') {
                $result[$key] = $tokens[$pos][1] ?? '';
                $pos++;
            } else { // '}' right after a key — malformed; treat as empty
                $result[$key] = '';
            }
        }

        return $result;
    }

    /**
     * @return list<array{0:string,1?:string}>
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        $len = \strlen($text);
        $i = 0;

        while ($i < $len) {
            $ch = $text[$i];

            if ($ch === '{' || $ch === '}') {
                $tokens[] = [$ch];
                $i++;
                continue;
            }

            if ($ch === '"') {
                $i++;
                $buf = '';
                while ($i < $len) {
                    $c = $text[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $next = $text[$i + 1];
                        $buf .= match ($next) {
                            'n' => "\n",
                            't' => "\t",
                            default => $next, // covers \" and \\
                        };
                        $i += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $i++;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                $tokens[] = ['str', $buf];
                continue;
            }

            $i++; // ignore all other characters (whitespace, banners, etc.)
        }

        return $tokens;
    }
}
