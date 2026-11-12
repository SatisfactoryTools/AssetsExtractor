<?php

declare(strict_types=1);

namespace App\Mods;

/**
 * The game's Docs file (a list of {NativeClass, Classes} groups), indexed by class
 * name, with room for extra classes that get written back in the same format.
 */
final class DocsFile
{
    /** @var array<string,array{native:string,entry:array<string,mixed>}> */
    private array $byClass = [];

    /** @var array<string,string>|null class name => class path, from every reference the file makes */
    private ?array $paths = null;

    /** @var array<string,array<string,array<string,true>>>|null native => key => values */
    private ?array $values = null;

    /** @param list<array{NativeClass:string,Classes:list<array<string,mixed>>}> $groups */
    private function __construct(private array $groups)
    {
        foreach ($groups as $group) {
            $native = self::nativePath((string) $group['NativeClass']);
            foreach ($group['Classes'] as $entry) {
                $this->byClass[(string) ($entry['ClassName'] ?? '')] = ['native' => $native, 'entry' => $entry];
            }
        }
    }

    public static function load(string $path): self
    {
        $contents = (string) file_get_contents($path);
        if (str_starts_with($contents, "\xFF\xFE") || (\strlen($contents) > 1 && $contents[1] === "\0")) {
            $contents = (string) iconv('UTF-16LE', 'UTF-8', $contents);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        /** @var list<array{NativeClass:string,Classes:list<array<string,mixed>>}> $groups */
        $groups = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return new self($groups);
    }

    public static function nativePath(string $groupName): string
    {
        return preg_match("/Class'(.*?)'$/", $groupName, $m) ? $m[1] : $groupName;
    }

    public static function groupName(string $nativePath): string
    {
        return "/Script/CoreUObject.Class'{$nativePath}'";
    }

    /** @return array<string,true> the string values a key takes in the classes of a native class */
    public function values(string $native, string $key): array
    {
        if ($this->values === null) {
            $this->values = [];
            foreach ($this->groups as $group) {
                $groupNative = self::nativePath((string) $group['NativeClass']);
                foreach ($group['Classes'] as $entry) {
                    foreach ($entry as $entryKey => $value) {
                        if (\is_string($value)) {
                            $this->values[$groupNative][$entryKey][$value] = true;
                        }
                    }
                }
            }
        }

        return $this->values[$native][$key] ?? [];
    }

    /**
     * The path of a class ("/Game/.../Desc_X.Desc_X_C", "/Script/FactoryGame.FGBuildGun"). Most entries carry
     * no FullName, but other entries refer to them by path.
     */
    public function classPath(string $className): ?string
    {
        if ($this->paths === null) {
            $this->paths = [];
            $json = json_encode($this->groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            preg_match_all('#(/(?:Game|Script)/[A-Za-z0-9_\-/]*)\.([A-Za-z0-9_\-]+)#', $json, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $package, $name]) {
                $this->paths[$name] ??= "{$package}.{$name}";
            }
            foreach ($this->byClass as $name => $class) {
                $fullName = (string) ($class['entry']['FullName'] ?? '');
                if (str_contains($fullName, ' ')) {
                    $this->paths[$name] = substr($fullName, strpos($fullName, ' ') + 1);
                }
            }
        }

        return $this->paths[$className] ?? null;
    }

    /** @return list<array{NativeClass:string,Classes:list<array<string,mixed>>}> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array{native:string,entry:array<string,mixed>}|null */
    public function find(string $className): ?array
    {
        return $this->byClass[$className] ?? null;
    }

    /**
     * A copy with the extra classes appended to their native class groups.
     * @param list<array{native:string,entry:array<string,mixed>}> $extra
     */
    public function with(array $extra): self
    {
        $groups = $this->groups;
        $index = [];
        foreach ($groups as $i => $group) {
            $index[self::nativePath((string) $group['NativeClass'])] = $i;
        }
        foreach ($extra as $class) {
            if (!isset($index[$class['native']])) {
                $index[$class['native']] = \count($groups);
                $groups[] = ['NativeClass' => self::groupName($class['native']), 'Classes' => []];
            }
            $groups[$index[$class['native']]]['Classes'][] = $class['entry'];
        }

        return new self($groups);
    }

    /**
     * A copy where existing classes are replaced by the given entries of the same name.
     * @param array<string,array<string,mixed>> $entries class name => entry
     */
    public function replacing(array $entries): self
    {
        $groups = $this->groups;
        foreach ($groups as &$group) {
            foreach ($group['Classes'] as &$entry) {
                $entry = $entries[(string) ($entry['ClassName'] ?? '')] ?? $entry;
            }
            unset($entry);
        }
        unset($group);

        return new self($groups);
    }

    /** @return list<string> package paths of the blueprint classes the file lists */
    public function blueprintPackages(): array
    {
        $packages = [];
        foreach ($this->groups as $group) {
            foreach ($group['Classes'] as $entry) {
                $fullName = (string) ($entry['FullName'] ?? '');
                if (str_starts_with($fullName, 'BlueprintGeneratedClass ')) {
                    $path = substr($fullName, \strlen('BlueprintGeneratedClass '));
                    $packages[substr($path, 0, (int) strrpos($path, '.'))] = true;
                }
            }
        }

        return array_keys($packages);
    }

    /** @return list<string> names of the classes that are blueprints rather than C++ classes */
    public function blueprintClassNames(): array
    {
        $names = [];
        foreach ($this->groups as $group) {
            foreach ($group['Classes'] as $entry) {
                $fullName = (string) ($entry['FullName'] ?? '');
                if (!str_starts_with($fullName, 'Class ') && isset($entry['ClassName'])) {
                    $names[] = (string) $entry['ClassName'];
                }
            }
        }

        return $names;
    }

    public function write(string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        file_put_contents($path, json_encode($this->groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
