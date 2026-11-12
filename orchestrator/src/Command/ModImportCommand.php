<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\Docs\ImageManifest;
use App\Mods\Adapters\ModAdapters;
use App\Mods\ContentLib\ContentLibConverter;
use App\Mods\ContentLib\ContentLibFolder;
use App\Mods\ContentLib\ContentLibIcons;
use App\Mods\DocsEntryBuilder;
use App\Mods\DocsFile;
use App\Mods\ModApiHandoff;
use App\Mods\ModClassExtractor;
use App\Mods\ModDataDiff;
use App\Mods\ModEntries;
use App\Mods\ModEnvironment;
use App\Mods\ModReport;
use App\Mods\ModSetResolver;
use App\Mods\ModSource;
use App\Mods\SafeClassNames;
use App\Mods\NativeDefaults;
use App\Mods\SmodPackage;
use App\Mods\SmrClient;
use App\Publish\Publisher;
use App\Publish\ReferenceRewriter;
use App\State\StateStore;
use App\Support\RunLock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a content mod into SFTools. The mod (and every mod it depends on) comes from
 * ficsit.app or a local .smod file; its classes are read from the paks, written as Docs
 * entries and parsed together with the base game's Docs. What the mod adds or changes
 * becomes the data of a mod version, stored through the API's `mods:import` command.
 */
#[AsCommand(name: 'mod:import', description: 'Import a mod from ficsit.app or a .smod file into SFTools')]
final class ModImportCommand extends Command
{
    private const PARENT_ROUNDS = 5;

    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('mod', InputArgument::OPTIONAL, 'ficsit.app mod reference, e.g. Easy_Recipes (not needed with --file)')
            ->addOption('mod-version', null, InputOption::VALUE_REQUIRED, 'ficsit.app version to import (default: the latest release)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Import this .smod file instead of downloading the mod')
            ->addOption('with', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A .smod file to use for one of the dependencies instead of downloading it')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Logical branch whose game install the mod is read against', 'stable')
            ->addOption('install-dir', null, InputOption::VALUE_REQUIRED, 'Read against this game install instead of the branch install')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Make the imported mods public (default: private to the configured owner)')
            ->addOption('class-map', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Treat a class built on the mod\'s own code as a standard game type, instead of the guessed one: a blueprint (Build_RP_MicroReactor_C=FGBuildableGeneratorFuel) or a mod C++ class, for all blueprints built on it (FFPlotRecipe=FGRecipe)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only write the data files and the report; publish no icons and do not call the API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $branch = (string) $input->getOption('branch');
        $installDir = (string) ($input->getOption('install-dir') ?? $this->config->dataPath('steam', $branch));
        $dryRun = (bool) $input->getOption('dry-run');
        $public = (bool) $input->getOption('public');

        $classMap = $this->classMap(array_map('strval', (array) $input->getOption('class-map')));
        if (\is_string($classMap)) {
            $io->error($classMap);

            return Command::FAILURE;
        }

        $reference = $input->getArgument('mod');
        $file = $input->getOption('file');
        if (($reference === null) === ($file === null)) {
            $io->error('Give either a ficsit.app mod reference or --file <path.smod>.');

            return Command::FAILURE;
        }

        $handoff = new ModApiHandoff((string) $this->config->get('mods.command', ''));
        if (!$dryRun && !$handoff->enabled()) {
            $io->note('mods.command is not configured, so this runs as --dry-run.');
            $dryRun = true;
        }

        $lock = new RunLock($this->config->dataPath('locks', "{$branch}.lock"));
        $locked = $input->getOption('install-dir') === null;
        if ($locked && !$lock->acquire()) {
            $io->error("A run for '{$branch}' is in progress.");

            return Command::FAILURE;
        }

        $label = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($reference ?? basename((string) $file, '.smod')));
        $workDir = $this->config->dataPath('artifacts', 'mods', date('Ymd-His') . '-' . $label);
        try {
            $env = ModEnvironment::create($this->config, $installDir);

            $local = [];
            $localFiles = [...($file !== null ? [(string) $file] : []), ...array_map('strval', (array) $input->getOption('with'))];
            foreach ($localFiles as $i => $path) {
                $package = SmodPackage::extract($path, "{$workDir}/local/{$i}");
                $local[$package->reference] = $package;
                if ($i === 0 && $file !== null) {
                    $reference = $package->reference;
                }
            }

            $smr = new SmrClient((string) $this->config->get('mods.smr_url', 'https://api.ficsit.app'));
            $resolver = new ModSetResolver($smr, $this->config->dataPath('mods', 'downloads'), "{$workDir}/mods", $local,
                static fn(string $line) => $io->text($line));
            $sources = $resolver->resolve((string) $reference, $input->getOption('mod-version'));

            $io->section('Mods');
            foreach ($sources as $source) {
                $io->text(sprintf('%s %s%s', $source->reference, $source->version,
                    $source->dependencies !== [] ? ' (needs ' . implode(', ', $source->dependencies) . ')' : ''));
            }

            $reports = $this->import($io, $env, $sources, $classMap, $workDir, $dryRun, $public, $handoff);
        } catch (\Throwable $e) {
            $io->error('Import failed: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }

        $io->section('Report');
        foreach ($reports as $report) {
            $io->text($report->lines());
            $io->newLine();
        }
        file_put_contents($workDir . '/report.json', json_encode(
            array_map(static fn(ModReport $r) => $r->toArray(), array_values($reports)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $io->success(($dryRun ? 'Dry run finished. ' : 'Import finished. ') . "Files: {$workDir}");

        return Command::SUCCESS;
    }

    /**
     * @param array<string,ModSource> $sources
     * @param array<string,string> $classMap
     * @return array<string,ModReport>
     */
    private function import(
        SymfonyStyle $io,
        ModEnvironment $env,
        array $sources,
        array $classMap,
        string $workDir,
        bool $dryRun,
        bool $public,
        ModApiHandoff $handoff,
    ): array {
        $io->section('Reading classes');
        $docs = DocsFile::load($env->docsFile);
        $mods = array_values(array_map(static fn(ModSource $s) => ['reference' => $s->reference, 'paksDir' => $s->package->paksDir, 'binariesDir' => $s->package->dir . '/Binaries'],
            array_filter($sources, static fn(ModSource $s) => $s->package->paksDir !== null)));
        $dump = $env->classExtractor->extract($env->installDir, "{$workDir}/dump", $mods,
            $docs->blueprintPackages(), $docs->blueprintClassNames(), ModClassExtractor::SINK_TABLES);

        $adapters = new ModAdapters();
        $adapterMap = [];
        foreach ($sources as $source) {
            foreach ($adapters->for($source)?->classMap() ?? [] as $class => $native) {
                $adapterMap[self::classMapKey($class)] = ModEntries::nativePath($native);
            }
        }
        $defaults = NativeDefaults::infer($docs, (array) ($dump['base']['classes'] ?? []));
        $builder = new DocsEntryBuilder($docs, $defaults, array_merge($adapterMap, $classMap));
        $builder->addSinkTables((array) ($dump['base']['dataTables'] ?? []));
        $modDumps = [];
        foreach ($dump['mods'] as $modDump) {
            $modDumps[(string) $modDump['reference']] = $modDump;
            $builder->addClasses((string) $modDump['reference'], (array) $modDump['classes']);
            $builder->addSinkTables((array) $modDump['dataTables']);
            $io->text(sprintf('%s: %d classes in %d files', $modDump['reference'], \count((array) $modDump['classes']), $modDump['packages']));
        }
        for ($round = 0; $round < self::PARENT_ROUNDS && ($missing = $builder->missingBaseParents()) !== []; $round++) {
            $parents = $env->classExtractor->extract($env->installDir, "{$workDir}/dump-parents-{$round}", [], $missing);
            $builder->addClasses('', (array) ($parents['base']['classes'] ?? []));
        }

        $io->section('Comparing with the base game');
        $reports = [];
        $entries = [];
        $overrides = [];
        $diffs = [];
        $parsed = [];
        $contentLib = array_filter(array_map(static fn(ModSource $s) => ContentLibFolder::in($s->package->dir, $s->reference), $sources));
        $icons = new ContentLibIcons();
        foreach ($sources as $ref => $source) {
            $built = $builder->build($ref);
            $report = $built['report'];
            $this->describe($report, $source, $modDumps[$ref] ?? []);
            $reports[$ref] = $report;

            $context = [];
            $inherited = [];
            $kitFolders = isset($contentLib[$ref]) ? [$contentLib[$ref]] : [];
            foreach ($this->closure($ref, $sources) as $dependency) {
                array_push($context, ...$entries[$dependency]);
                $inherited = array_replace($inherited, $overrides[$dependency]);
                if (isset($contentLib[$dependency])) {
                    $kitFolders[] = $contentLib[$dependency];
                }
            }

            $modEntries = new ModEntries($built['entries'], $context, $docs, $defaults, $inherited);
            if (isset($contentLib[$ref])) {
                (new ContentLibConverter($contentLib[$ref], $kitFolders, $modEntries, $report, $icons))->convert();
            }
            $adapters->describe($source, $report);
            $adapter = $adapters->for($source);
            $adapter?->patchEntries($modEntries, $report);
            $entries[$ref] = SafeClassNames::apply($modEntries->all(), $report);
            $overrides[$ref] = SafeClassNames::applyKeyed($modEntries->overrides(), $report);
            if ($overrides[$ref] !== []) {
                $report->note(sprintf('it changes %d existing class(es): %s', \count($overrides[$ref]), implode(', ', array_keys($overrides[$ref]))));
            }

            $contextKey = hash('sha256', json_encode([$context, $inherited], JSON_THROW_ON_ERROR));
            $without = $parsed[$contextKey] ??= $env->parse(self::docsWith($docs, $context, $inherited), "{$workDir}/parse/{$ref}-base");
            $with = $env->parse(self::docsWith($docs, [...$context, ...$entries[$ref]], array_replace($inherited, $overrides[$ref])), "{$workDir}/parse/{$ref}");

            $diff = ModDataDiff::clean(ModDataDiff::diff($without, $with), $with, $report);
            $diff = ModDataDiff::restorePrecision($diff, $entries[$ref]);
            $adapter?->patchData($diff, $report);
            ModDataDiff::summarize($diff, $report);
            $diffs[$ref] = $diff;
            $io->text(sprintf('%s: %s', $ref, $report->result === [] ? 'nothing to import'
                : implode(', ', array_map(static fn($k, $v) => "{$v} {$k}", array_keys($report->result), $report->result))));
        }

        $imported = array_keys(array_filter($diffs, static fn(array $diff) => $diff !== []));
        if ($imported === []) {
            return $reports;
        }

        $rawFiles = [];
        $union = [];
        if (!is_dir("{$workDir}/data") && !mkdir("{$workDir}/data", 0o775, true) && !is_dir("{$workDir}/data")) {
            throw new \RuntimeException("Cannot create {$workDir}/data");
        }
        foreach ($imported as $ref) {
            $rawFiles[$ref] = "{$workDir}/data/{$ref}.raw.json";
            file_put_contents($rawFiles[$ref], json_encode($diffs[$ref], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach (ImageManifest::extract($rawFiles[$ref]) as $entry) {
                $union[$entry['assetPath']] = $entry;
            }
        }

        $idMap = [];
        if (!$dryRun && $union !== []) {
            $io->section('Icons');
            $imagesDir = "{$workDir}/images";
            $textures = array_values(array_filter($union, static fn(array $entry) => !$icons->has($entry['assetPath'])));
            $pngs = array_values(array_filter(array_keys($union), static fn(string $path) => $icons->has($path)));
            $result = ['assets' => [], 'failed' => 0, 'total' => 0];
            if ($textures !== []) {
                ImageManifest::write($textures, "{$imagesDir}/image-manifest.json");
                $result = $env->imageExtractor->extract("{$imagesDir}/image-manifest.json", $env->installDir, $imagesDir, null,
                    $env->engineCandidates, array_values(array_filter(array_map(static fn(ModSource $s) => $s->package->paksDir, $sources))));
            }
            if ($pngs !== []) {
                $sizes = array_map('intval', explode(',', (string) $this->config->get('extraction.sizes', '256,64')));
                $rendered = $icons->render($pngs, $imagesDir, $sizes);
                $result['assets'] = [...$result['assets'], ...$rendered];
                $result['total'] += \count($rendered);
                $result['failed'] += \count(array_filter($rendered, static fn(array $asset) => !$asset['ok']));
            }
            if ($result['failed'] > 0) {
                $io->warning(sprintf('%d of %d icons could not be read; those entries get no icon.', $result['failed'], $result['total']));
            }
            $publisher = new Publisher(new StateStore($this->config), (int) $this->config->get('publish.id_length', 16));
            $imagesOut = rtrim((string) $this->config->require('publish.api_data_dir'), '/')
                . '/' . trim((string) $this->config->get('publish.images_subdir', 'images'), '/');
            $published = $publisher->publish($result, $imagesDir, $imagesOut, substr('mod:' . $imported[array_key_last($imported)], 0, 32));
            $idMap = $published['idMap'];
            $io->text(sprintf('%d icons, %d new.', $published['unique'], $published['new']));
        }

        $rewriter = new ReferenceRewriter();
        $versionIds = [];
        foreach ($imported as $ref) {
            $dataFile = "{$workDir}/data/{$ref}.json";
            if ($dryRun) {
                file_put_contents($dataFile, json_encode(['data' => $diffs[$ref], 'metadata' => new \stdClass()],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                continue;
            }
            $rewriter->rewrite($rawFiles[$ref], $idMap, $dataFile);

            $dependsOn = [];
            foreach ($this->importedDependencies($ref, $sources, $imported) as $dependency) {
                $dependsOn[] = $versionIds[$dependency];
            }
            $summary = $handoff->import($dataFile, $sources[$ref], $public, $dependsOn, "{$workDir}/data/{$ref}.summary.json");
            $versionIds[$ref] = $summary['modVersionId'];
            $reports[$ref]->note(sprintf('stored as mod %s, version %s (%s)', $summary['modId'], $summary['modVersionId'],
                $summary['createdVersion'] ? 'new version' : 'data replaced'));
        }

        return $reports;
    }

    /** @param array<string,mixed> $modDump */
    private function describe(ModReport $report, ModSource $source, array $modDump): void
    {
        $report->hasCode = $source->package->hasCode;
        $report->engine = (string) ($modDump['engine'] ?? '');
        $report->legacy = (bool) ($modDump['legacy'] ?? false);

        $overrides = (array) ($modDump['gameOverrides'] ?? []);
        if ($overrides !== []) {
            $report->note(sprintf('it replaces %d base-game file(s); those changes are not imported', \count($overrides)));
        }
        if (\in_array('ContentLib', $source->dependencies, true) && ContentLibFolder::in($source->package->dir, $source->reference) === null) {
            $report->note('it uses ContentLib but ships no ContentLib folder; content it makes from code while the game runs is not imported');
        }
        foreach ((array) ($modDump['failedPackages'] ?? []) as $failed) {
            $report->note("could not read {$failed['path']}: {$failed['error']}");
        }
        foreach ((array) ($modDump['classes'] ?? []) as $class) {
            $properties = (array) ($class['properties'] ?? []);
            if (!\array_key_exists('bRootModule', $properties) && !\array_key_exists('mSchematics', $properties)) {
                continue;
            }
            foreach ($properties as $key => $value) {
                if ($key !== 'mSchematics' && preg_match('/Schematics$/', (string) $key) && $value !== '') {
                    $report->note("{$class['className']} also adds schematics through {$key} (only in some situations); they are imported as always available");
                }
            }
        }
    }

    /**
     * Every mod the given one needs, directly or indirectly, in import order.
     * @param array<string,ModSource> $sources
     * @return list<string>
     */
    private function closure(string $ref, array $sources): array
    {
        $needed = [];
        $stack = $sources[$ref]->dependencies;
        while ($stack !== []) {
            $dependency = array_pop($stack);
            if (isset($needed[$dependency]) || !isset($sources[$dependency])) {
                continue;
            }
            $needed[$dependency] = true;
            array_push($stack, ...$sources[$dependency]->dependencies);
        }

        return array_values(array_filter(array_keys($sources), static fn(string $r) => isset($needed[$r])));
    }

    /**
     * The dependencies that became SFTools mods. A dependency with nothing to import is
     * skipped over, so its own imported dependencies are linked instead.
     * @param array<string,ModSource> $sources
     * @param list<string> $imported
     * @return list<string>
     */
    private function importedDependencies(string $ref, array $sources, array $imported): array
    {
        $result = [];
        $seen = [];
        $stack = $sources[$ref]->dependencies;
        while ($stack !== []) {
            $dependency = array_shift($stack);
            if (isset($seen[$dependency]) || !isset($sources[$dependency])) {
                continue;
            }
            $seen[$dependency] = true;
            if (\in_array($dependency, $imported, true)) {
                $result[] = $dependency;
            } else {
                array_push($stack, ...$sources[$dependency]->dependencies);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $pairs
     * @return array<string,string>|string class name => native class path, or an error
     */
    private function classMap(array $pairs): array|string
    {
        $map = [];
        foreach ($pairs as $pair) {
            if (!preg_match('/^([A-Za-z0-9_\-.\/]+)=([A-Za-z0-9_.\/]+)$/', $pair, $m)) {
                return "--class-map expects Class_C=FGNativeClass, got '{$pair}'.";
            }
            $map[self::classMapKey($m[1])] = ModEntries::nativePath($m[2]);
        }

        return $map;
    }

    /**
     * The Docs with extra classes, where overrides replace the classes they change: a dependency's class in the
     * extra list, or a base-game class.
     * @param list<array{native:string,entry:array<string,mixed>}> $extra
     * @param array<string,array{native:string,entry:array<string,mixed>}> $overrides
     */
    private static function docsWith(DocsFile $docs, array $extra, array $overrides): DocsFile
    {
        foreach ($extra as &$class) {
            $class = $overrides[(string) $class['entry']['ClassName']] ?? $class;
        }
        unset($class);
        $listed = array_flip(array_map(static fn(array $class) => (string) $class['entry']['ClassName'], $extra));
        $base = array_map(static fn(array $override) => $override['entry'], array_diff_key($overrides, $listed));

        return ($base === [] ? $docs : $docs->replacing($base))->with($extra);
    }

    /** A C++ class may be given by its full script path. */
    private static function classMapKey(string $class): string
    {
        return str_contains($class, '.') ? substr($class, strrpos($class, '.') + 1) : $class;
    }
}
