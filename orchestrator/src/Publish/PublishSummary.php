<?php

declare(strict_types=1);

namespace App\Publish;

/**
 * What the API import decided per variant (written by its --summary-file):
 * attached to an existing SFTools version because only cosmetics changed, or a
 * new version because planner data differs. Rendered for notifications, with
 * the command that overrides a wrong decision.
 */
final class PublishSummary
{
    /**
     * @param list<array<string,mixed>> $variants
     */
    public function __construct(
        public readonly array $variants,
    ) {
    }

    public static function read(string $path): ?self
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!\is_array($data) || !\is_array($data['variants'] ?? null)) {
            return null;
        }

        return new self(array_values(array_filter($data['variants'], 'is_array')));
    }

    /** One line per variant, e.g. "default: attached to stable-1 (1.2.3.0 – 1.2.3.1)". */
    public function shortLines(): array
    {
        $lines = [];
        foreach ($this->variants as $variant) {
            $lines[] = sprintf('%s: %s %s%s',
                ($variant['ficsmas'] ?? false) ? 'ficsmas' : 'default',
                self::actionLabel((string) ($variant['action'] ?? '?')),
                (string) ($variant['slug'] ?? '?'),
                self::range((array) ($variant['gameVersions'] ?? [])),
            );
        }

        return $lines;
    }

    /**
     * Short lines plus, for every new version, the planner changes that caused
     * it and the command to attach the build to the compared version instead.
     *
     * @param string $importCommand e.g. "php bin/console publish --buildId 1 --branch stable"
     */
    public function detailLines(string $importCommand): array
    {
        $lines = [];
        foreach ($this->variants as $index => $variant) {
            $lines[] = $this->shortLines()[$index];
            $diff = $variant['diff'] ?? null;
            $comparedWith = $variant['comparedWith'] ?? null;
            if (($variant['action'] ?? null) !== 'created' || !\is_array($diff) || !\is_string($comparedWith)) {
                continue;
            }

            $lines[] = sprintf('  %d planner change(s) vs %s:', (int) ($diff['count'] ?? 0), $comparedWith);
            foreach ((array) ($diff['changes'] ?? []) as $change) {
                $lines[] = '    ' . $change;
            }
            if ($importCommand !== '') {
                $lines[] = '  Keep it on ' . $comparedWith . ' instead: ' . $importCommand . ' --attach=' . $comparedWith;
            }
        }

        return $lines;
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'created' => 'new version',
            'attached' => 'attached to',
            'merged' => 'merged into',
            'refreshed' => 'refreshed',
            default => $action,
        };
    }

    private static function range(array $gameVersions): string
    {
        if ($gameVersions === []) {
            return '';
        }
        $first = (string) reset($gameVersions);
        $last = (string) end($gameVersions);

        return ' (' . ($first === $last ? $first : $first . ' – ' . $last) . ')';
    }
}
