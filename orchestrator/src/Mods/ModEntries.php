<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * The Docs entries built for one mod, as a mod adapter sees and changes them. Classes of
 * the mod's dependencies and of the base game can be looked up but not changed.
 */
final class ModEntries
{
    /** @var array<string,array{native:string,entry:array<string,mixed>}> class name => entry */
    private array $entries = [];

    /** @var array<string,array{native:string,entry:array<string,mixed>}> class name => dependency entry */
    private array $context = [];

    /**
     * @param list<array{native:string,entry:array<string,mixed>}> $entries
     * @param list<array{native:string,entry:array<string,mixed>}> $context entries of the mod's dependencies
     */
    public function __construct(
        array $entries,
        array $context,
        private readonly DocsFile $docs,
        private readonly NativeDefaults $defaults,
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

    public function has(string $className): bool
    {
        return isset($this->entries[$className]);
    }

    /**
     * A class of the mod, a dependency or the base game.
     * @return array<string,mixed>
     */
    public function get(string $className): array
    {
        $found = $this->entries[$className] ?? $this->context[$className] ?? $this->docs->find($className);
        if ($found === null) {
            throw new \RuntimeException("{$className} is not in the mod, its dependencies or the game");
        }

        return $found['entry'];
    }

    /** @param array<string,mixed> $entry */
    public function set(string $className, array $entry): void
    {
        if (!isset($this->entries[$className])) {
            throw new \RuntimeException("{$className} is not a class of this mod");
        }
        $this->entries[$className]['entry'] = $entry;
    }

    /**
     * A new class, filled up with the defaults of its native class.
     * @param array<string,mixed> $entry must have ClassName; FullName is made up under $package when missing
     */
    public function add(string $native, array $entry, string $package): void
    {
        $native = self::nativePath($native);
        $className = (string) $entry['ClassName'];
        if (isset($this->entries[$className]) || $this->docs->find($className) !== null) {
            throw new \RuntimeException("{$className} already exists");
        }
        $entry += [
            'FullName' => sprintf('BlueprintGeneratedClass %s/%s.%s', rtrim($package, '/'), preg_replace('/_C$/', '', $className), $className),
        ];
        $this->entries[$className] = ['native' => $native, 'entry' => $entry + $this->defaults->forClass($native)];
    }

    public function remove(string $className): void
    {
        unset($this->entries[$className]);
    }

    /** "/Mod/Path/X.X_C", as mProducedIn lists buildings */
    public function path(string $className): string
    {
        $fullName = (string) ($this->get($className)['FullName'] ?? '');
        $space = strpos($fullName, ' ');
        if ($fullName === '' || $space === false) {
            throw new \RuntimeException("{$className} has no class path");
        }

        return substr($fullName, $space + 1);
    }

    /** "/Script/Engine.BlueprintGeneratedClass'/Mod/Path/X.X_C'", as ingredients and unlocks refer to classes */
    public function reference(string $className): string
    {
        return "/Script/Engine.BlueprintGeneratedClass'{$this->path($className)}'";
    }

    /**
     * Docs amount list, e.g. ((ItemClass="…",Amount=1),(…)).
     * @param array<string,int> $amounts item class name => amount
     */
    public function amounts(array $amounts): string
    {
        $parts = [];
        foreach ($amounts as $item => $amount) {
            $parts[] = sprintf('(ItemClass="%s",Amount=%d)', $this->reference($item), $amount);
        }

        return '(' . implode(',', $parts) . ')';
    }

    /** @param list<string> $recipes recipe class names the schematic unlocks in addition */
    public function unlockRecipes(string $schematic, array $recipes): void
    {
        $entry = $this->get($schematic);
        $added = implode(',', array_map(fn(string $recipe) => '"' . $this->reference($recipe) . '"', $recipes));
        $unlocks = \is_array($entry['mUnlocks'] ?? null) ? $entry['mUnlocks'] : [];
        foreach ($unlocks as &$unlock) {
            if (($unlock['Class'] ?? '') === 'BP_UnlockRecipe_C') {
                $list = trim((string) ($unlock['mRecipes'] ?? ''));
                $unlock['mRecipes'] = $list === '' || $list === '()' ? "({$added})" : substr($list, 0, -1) . ",{$added})";
                $added = null;
                break;
            }
        }
        unset($unlock);
        if ($added !== null) {
            $unlocks[] = ['Class' => 'BP_UnlockRecipe_C', 'mRecipes' => "({$added})"];
        }
        $entry['mUnlocks'] = $unlocks;
        $this->set($schematic, $entry);
    }
}
