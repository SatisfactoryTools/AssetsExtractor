<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * The Docs entries built for one mod, as ContentLib files and mod adapters see and change
 * them. A change to a class of a dependency or the base game is kept as an override,
 * which replaces that class when the mod is parsed.
 */
final class ModEntries
{
    /** @var array<string,array{native:string,entry:array<string,mixed>}> class name => entry */
    private array $entries = [];

    /** @var array<string,array{native:string,entry:array<string,mixed>}> class name => dependency entry */
    private array $context = [];

    /** @var array<string,array{native:string,entry:array<string,mixed>}> class name => changed class of a dependency or the game */
    private array $overrides = [];

    /** @var array<string,string>|null lowercase name => class name of the dependencies and the game */
    private ?array $caseless = null;

    /**
     * @param list<array{native:string,entry:array<string,mixed>}> $entries
     * @param list<array{native:string,entry:array<string,mixed>}> $context entries of the mod's dependencies
     * @param array<string,array{native:string,entry:array<string,mixed>}> $overrides what the dependencies changed
     */
    public function __construct(
        array $entries,
        array $context,
        private readonly DocsFile $docs,
        private readonly NativeDefaults $defaults,
        private readonly array $inheritedOverrides = [],
    ) {
        foreach ($entries as $entry) {
            $this->entries[(string) $entry['entry']['ClassName']] = $entry;
        }
        foreach ($context as $entry) {
            $this->context[(string) $entry['entry']['ClassName']] = $entry;
        }
    }

    /** FGRecipe or /Script/FactoryGame.FGRecipe → /Script/FactoryGame.FGRecipe */
    public static function nativePath(string $native): string
    {
        return str_starts_with($native, '/Script/') ? $native : '/Script/FactoryGame.' . $native;
    }

    /** @return list<array{native:string,entry:array<string,mixed>}> */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /** @return array<string,array{native:string,entry:array<string,mixed>}> */
    public function overrides(): array
    {
        return $this->overrides;
    }

    public function has(string $className): bool
    {
        return $this->find($className) !== null;
    }

    /**
     * A class of the mod, a dependency or the game.
     * @return array<string,mixed>
     */
    public function get(string $className): array
    {
        return ($this->find($className) ?? throw new \RuntimeException("{$className} is not in the mod, its dependencies or the game"))['entry'];
    }

    public function native(string $className): string
    {
        return ($this->find($className) ?? throw new \RuntimeException("{$className} is not in the mod, its dependencies or the game"))['native'];
    }

    /**
     * Changes a class; one that is not the mod's own is overridden.
     * @param array<string,mixed> $entry
     */
    public function set(string $className, array $entry): void
    {
        if (isset($this->entries[$className])) {
            $this->entries[$className]['entry'] = $entry;

            return;
        }
        $found = $this->find($className) ?? throw new \RuntimeException("{$className} is not in the mod, its dependencies or the game");
        $this->overrides[$className] = ['native' => $found['native'], 'entry' => $entry];
    }

    /**
     * A new class, filled up with the defaults of its native class.
     * @param array<string,mixed> $entry must have ClassName; FullName is made up under $package when missing
     */
    public function add(string $native, array $entry, string $package): void
    {
        $native = self::nativePath($native);
        $className = (string) $entry['ClassName'];
        if ($this->has($className)) {
            throw new \RuntimeException("{$className} already exists");
        }
        $entry += [
            'FullName' => sprintf('BlueprintGeneratedClass %s/%s.%s', rtrim($package, '/'), preg_replace('/_C$/', '', $className), $className),
        ];
        $this->entries[$className] = ['native' => $native, 'entry' => $entry + $this->defaults($native)];
    }

    public function remove(string $className): void
    {
        unset($this->entries[$className]);
    }

    /** @return array<string,mixed> */
    public function defaults(string $native): array
    {
        return $this->defaults->forClass(self::nativePath($native));
    }

    public function knowsNative(string $native): bool
    {
        return $this->defaults->has(self::nativePath($native));
    }

    /** @return array<string,true> the values the game uses for an enum key of a native class */
    public function enumValues(string $native, string $key): array
    {
        return $this->docs->values(self::nativePath($native), $key);
    }

    /**
     * The class a short name means, the way ContentLib resolves names: "_C" may be left out, a full path may be
     * given, and the first class found wins whatever its type. Also finds classes the game only refers to, like
     * BP_WorkBenchComponent_C.
     */
    public function resolve(string $name): ?string
    {
        $name = trim($name);
        if (str_contains($name, '.')) {
            $name = substr($name, strrpos($name, '.') + 1);
        }
        $name = rtrim($name, "'\"");
        $name = (string) preg_replace('/^Default__/', '', $name);
        $candidates = preg_match('/_C$/i', $name) ? [$name] : [$name . '_C', $name];
        foreach ($candidates as $candidate) {
            if ($this->has($candidate) || $this->docs->classPath($candidate) !== null) {
                return $candidate;
            }
        }
        // Unreal compares names without case, and mods rely on it (Desc_LiquidTurbofuel).
        if ($this->caseless === null) {
            $this->caseless = [];
            foreach ([...$this->docs->knownClassNames(), ...array_keys($this->context), ...array_keys($this->inheritedOverrides)] as $known) {
                $this->caseless[strtolower((string) $known)] = (string) $known;
            }
        }
        foreach ($candidates as $candidate) {
            foreach (array_keys($this->entries) as $own) {
                if (strcasecmp($own, $candidate) === 0) {
                    return $own;
                }
            }
            if (isset($this->caseless[strtolower($candidate)])) {
                return $this->caseless[strtolower($candidate)];
            }
        }

        return null;
    }

    /** "/Mod/Path/X.X_C", as mProducedIn lists buildings */
    public function path(string $className): string
    {
        $fullName = (string) ($this->find($className)['entry']['FullName'] ?? '');
        if (str_contains($fullName, ' ')) {
            return substr($fullName, strpos($fullName, ' ') + 1);
        }

        return $this->docs->classPath($className) ?? throw new \RuntimeException("{$className} has no class path");
    }

    /** "/Script/Engine.BlueprintGeneratedClass'/Mod/Path/X.X_C'", as ingredients and unlocks refer to classes */
    public function reference(string $className): string
    {
        $path = $this->path($className);

        return str_starts_with($path, '/Script/')
            ? "/Script/CoreUObject.Class'{$path}'"
            : "/Script/Engine.BlueprintGeneratedClass'{$path}'";
    }

    /**
     * Docs amount list, e.g. ((ItemClass="…",Amount=1),(…)).
     * @param array<string,int|float> $amounts item class name => amount
     */
    public function amounts(array $amounts): string
    {
        $parts = [];
        foreach ($amounts as $item => $amount) {
            $parts[] = sprintf('(ItemClass="%s",Amount=%s)', $this->reference($item), self::number($amount));
        }

        return '(' . implode(',', $parts) . ')';
    }

    /**
     * Docs reference list, e.g. ("…","…").
     * @param list<string> $classNames
     */
    public function references(array $classNames): string
    {
        return '(' . implode(',', array_map(fn(string $class) => '"' . $this->reference($class) . '"', $classNames)) . ')';
    }

    /** Joins two Docs lists like "(a,b)" and "(c)" into "(a,b,c)". */
    public static function joinLists(string $list, string $more): string
    {
        $list = trim($list);
        $more = trim($more);
        if ($list === '' || $list === '()') {
            return $more;
        }
        if ($more === '' || $more === '()') {
            return $list;
        }

        return substr($list, 0, -1) . ',' . substr($more, 1);
    }

    public static function number(int|float $value): string
    {
        return (float) $value === floor((float) $value) && abs((float) $value) < PHP_INT_MAX
            ? (string) (int) $value
            : rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }

    /** @param list<string> $recipes recipe class names the schematic unlocks in addition */
    public function unlockRecipes(string $schematic, array $recipes): void
    {
        $entry = $this->get($schematic);
        $entry['mUnlocks'] = self::addUnlock($entry['mUnlocks'] ?? [], 'BP_UnlockRecipe_C', 'mRecipes', $this->references($recipes));
        $this->set($schematic, $entry);
    }

    /**
     * Adds to the list of the unlock subobject of the given class, creating it when missing.
     * @param mixed $unlocks the entry's mUnlocks
     * @return list<array<string,mixed>>
     */
    public static function addUnlock(mixed $unlocks, string $class, string $key, string $list, bool $clear = false): array
    {
        $unlocks = \is_array($unlocks) ? array_values($unlocks) : [];
        foreach ($unlocks as &$unlock) {
            if (($unlock['Class'] ?? '') === $class) {
                $unlock[$key] = $clear ? $list : self::joinLists((string) ($unlock[$key] ?? ''), $list);

                return $unlocks;
            }
        }
        unset($unlock);
        if ($list !== '' && $list !== '()') {
            $unlocks[] = ['Class' => $class, $key => $list];
        }

        return $unlocks;
    }

    /** @return array{native:string,entry:array<string,mixed>}|null */
    private function find(string $className): ?array
    {
        return $this->entries[$className] ?? $this->overrides[$className] ?? $this->inheritedOverrides[$className]
            ?? $this->context[$className] ?? $this->docs->find($className);
    }
}
