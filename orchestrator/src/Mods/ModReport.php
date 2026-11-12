<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * What the importer found in one mod: what it imported, what it had to leave out and
 * why, and hints about content the files alone cannot show.
 */
final class ModReport
{
    public int $classes = 0;

    public bool $hasCode = false;

    public bool $legacy = false;

    public string $engine = '';

    /** @var array<string,int> native class => imported classes */
    public array $imported = [];

    /** @var array<string,string> class name => reason */
    public array $skipped = [];

    /** @var array<string,int> native class => classes of a type SFTools does not read */
    public array $ignoredTypes = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var array<string,array{native:string,codeClass:?string}> class name => type guessed for a class built on the mod's code */
    public array $guessed = [];

    /** The hand-written adapter used for this mod, if any. */
    public ?string $adapter = null;

    /** @var array<string,int> category => new or changed entries in the result */
    public array $result = [];

    public function __construct(public readonly string $reference)
    {
    }

    public function count(string $native): void
    {
        $this->imported[$native] = ($this->imported[$native] ?? 0) + 1;
    }

    public function skip(string $className, string $reason): void
    {
        $this->skipped[$className] = $reason;
    }

    public function ignoreType(string $native): void
    {
        $this->ignoredTypes[$native] = ($this->ignoredTypes[$native] ?? 0) + 1;
    }

    public function guess(string $className, string $native, ?string $codeClass): void
    {
        $this->guessed[$className] = ['native' => $native, 'codeClass' => $codeClass];
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function isBlueprintOnly(): bool
    {
        return !$this->hasCode;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [sprintf(
            '%s: %s mod, %d classes read%s',
            $this->reference,
            $this->hasCode ? 'code + blueprint' : 'blueprint-only',
            $this->classes,
            $this->legacy ? " (old {$this->engine} files)" : '',
        )];

        if ($this->adapter !== null) {
            $lines[] = "  Adapter: {$this->adapter}";
        }

        if ($this->result !== []) {
            $parts = [];
            foreach ($this->result as $category => $count) {
                $parts[] = "{$count} {$category}";
            }
            $lines[] = '  Imported: ' . implode(', ', $parts);
        } else {
            $lines[] = '  Imported: nothing SFTools can use';
        }

        if ($this->skipped !== []) {
            $lines[] = sprintf('  Left out (%d):', \count($this->skipped));
            foreach ($this->skipped as $class => $reason) {
                $lines[] = "    - {$class}: {$reason}";
            }
        }

        if ($this->ignoredTypes !== []) {
            arsort($this->ignoredTypes);
            $parts = [];
            foreach ($this->ignoredTypes as $native => $count) {
                $parts[] = self::shortNative($native) . " ({$count})";
            }
            $lines[] = '  Types SFTools does not read: ' . implode(', ', $parts);
        }

        if ($this->guessed !== []) {
            $groups = [];
            foreach ($this->guessed as $class => $guess) {
                $groups[($guess['codeClass'] ?? 'unknown C++ class') . ' → ' . self::shortNative($guess['native'])][] = $class;
            }
            $lines[] = sprintf('  Guessed types (%d classes built on the mod\'s own code; check them, --class-map overrides):', \count($this->guessed));
            foreach ($groups as $group => $classes) {
                $shown = \array_slice($classes, 0, 4);
                $lines[] = sprintf('    - %s (%d): %s%s', $group, \count($classes), implode(', ', $shown), \count($classes) > 4 ? ', …' : '');
            }
        }

        foreach ($this->notes as $note) {
            $lines[] = "  Note: {$note}";
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'blueprintOnly' => $this->isBlueprintOnly(),
            'legacy' => $this->legacy,
            'engine' => $this->engine,
            'classes' => $this->classes,
            'imported' => $this->imported,
            'result' => $this->result,
            'skipped' => $this->skipped,
            'ignoredTypes' => $this->ignoredTypes,
            'guessed' => $this->guessed,
            'adapter' => $this->adapter,
            'notes' => $this->notes,
        ];
    }

    private static function shortNative(string $native): string
    {
        $dot = strrpos($native, '.');

        return $dot === false ? $native : substr($native, $dot + 1);
    }
}
