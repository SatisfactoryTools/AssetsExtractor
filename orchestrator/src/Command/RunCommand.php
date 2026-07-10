<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\GameVersion;
use App\Docs\DocsLocator;
use App\Docs\DocsParser;
use App\Docs\ImageManifest;
use App\Images\ImageExtractor;
use App\Notify\Notifier;
use App\Publish\ApiHandoff;
use App\Publish\Publisher;
use App\Publish\ReferenceRewriter;
use App\State\StateStore;
use App\Steam\SteamClient;
use App\Steam\SteamDownloader;
use App\Support\RunLock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrator entrypoint. Per branch, under a lock: check build id -> download ->
 * docs-diff -> parse -> extract images -> content-address/dedup -> rewrite refs ->
 * hand off to the API, committing state only after success.
 */
#[AsCommand(name: 'run', description: 'Run the extraction pipeline for a branch')]
final class RunCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('branch', null, InputOption::VALUE_REQUIRED,
                'Logical branch to run (e.g. stable, experimental), or "all"', 'all')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Run even if the build id is unchanged');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $branchArg = (string) $input->getOption('branch');
        $force = (bool) $input->getOption('force');

        $configured = $this->config->branches();
        $branches = $branchArg === 'all'
            ? array_keys($configured)
            : [$branchArg];

        foreach ($branches as $branch) {
            if (!isset($configured[$branch])) {
                $io->error("Unknown branch '{$branch}'. Configured: " . implode(', ', array_keys($configured)));

                return Command::FAILURE;
            }
        }

        $steam = new SteamClient(
            (string) $this->config->require('steam.steamcmd'),
            (int) $this->config->require('steam.app_id'),
        );
        $store = new StateStore($this->config);
        $notifier = new Notifier(
            (string) $this->config->get('notifications.log_file', $this->config->dataPath('logs', 'notifications.log')),
            (string) $this->config->get('notifications.discord.webhook_url', ''),
            (string) $this->config->get('notifications.discord.mention', ''),
        );

        // One Steam fetch for all branches.
        $io->text('Querying Steam for current build ids…');
        try {
            $current = $steam->getBranchBuildIds($configured);
        } catch (\Throwable $e) {
            $io->error('Steam query failed: ' . $e->getMessage());
            $notifier->failure('Extractor: Steam query failed', $e->getMessage());

            return Command::FAILURE;
        }

        $downloader = new SteamDownloader(
            (string) $this->config->require('steam.steamcmd'),
            (int) $this->config->require('steam.app_id'),
            $this->config->get('steam.username') !== '' ? (string) $this->config->get('steam.username') : null,
        );
        $docs = new DocsLocator((string) $this->config->get('docs.locale', 'en-US'));
        $parser = new DocsParser();
        $extractor = new ImageExtractor(
            (string) $this->config->get('extraction.dotnet', 'dotnet'),
            (string) $this->config->require('extraction.dll'),
            (string) $this->config->get('extraction.sizes', '256,64'),
            (array) $this->config->get('extraction.engine_candidates', ['GAME_UE5_6']),
            (string) $this->config->get('extraction.aes_key', ''),
        );
        $publisher = new Publisher($store, (int) $this->config->get('publish.id_length', 16));
        $rewriter = new ReferenceRewriter();
        $handoff = new ApiHandoff(
            (string) $this->config->require('publish.api_data_dir'),
            (string) $this->config->get('publish.versions_subdir', 'versions'),
            (string) $this->config->get('publish.command', ''),
        );

        $exit = Command::SUCCESS;
        foreach ($branches as $branch) {
            $exit = max($exit, $this->runBranch(
                $io, $store, $notifier, $downloader, $docs, $parser, $extractor, $publisher, $rewriter, $handoff,
                $branch, $configured[$branch], $current[$branch] ?? null, $force,
            ));
        }

        return $exit;
    }

    private function runBranch(
        SymfonyStyle $io,
        StateStore $store,
        Notifier $notifier,
        SteamDownloader $downloader,
        DocsLocator $docs,
        DocsParser $parser,
        ImageExtractor $extractor,
        Publisher $publisher,
        ReferenceRewriter $rewriter,
        ApiHandoff $handoff,
        string $branch,
        string $betaKey,
        ?string $currentBuild,
        bool $force,
    ): int {
        $io->section("Branch: {$branch}");

        $lock = new RunLock($this->config->dataPath('locks', "{$branch}.lock"));
        if (!$lock->acquire()) {
            $io->warning("Another run for '{$branch}' is in progress — skipping.");

            return Command::SUCCESS;
        }

        $runId = null;
        $gameVersion = null;
        try {
            if ($currentBuild === null) {
                $io->warning("Branch '{$branch}' not found in Steam app info — skipping.");

                return Command::SUCCESS;
            }

            $runId = $store->startRun($branch, $currentBuild);
            $last = $store->getLastBuildId($branch);

            if ($last === $currentBuild && !$force) {
                $io->text("Up to date (build {$currentBuild}). Nothing to do.");
                $store->finishRun($runId, 'skipped', 'no new build');

                return Command::SUCCESS;
            }

            $io->text(sprintf(
                'New build for %s: %s (was %s)%s',
                $branch, $currentBuild, $last ?? 'none', $force ? ' [--force]' : '',
            ));

            // --- Disk-space guard (a fresh client is ~30 GB; an update needs far less) ---
            $installDir = $this->config->dataPath('steam', $branch);
            $this->assertFreeSpace($this->config->dataPath(), $downloader->isFullyInstalled($installDir));

            // --- Component B: download / delta-update the branch install (with retry) ---
            $io->text("Updating install via steamcmd → {$installDir}");
            $cleanExit = $this->downloadWithRetry($io, $downloader, $installDir, $betaKey);
            if (!$cleanExit) {
                $io->warning(
                    'steamcmd crashed in its post-install script step (harmless Proton segfault on '
                    . 'headless Linux). The app is fully installed (verified via StateFlags) — continuing.'
                );
            }

            // --- Read the human game version + engine from the install ---
            $gv = GameVersion::read($installDir);
            if ($gv !== null) {
                $gameVersion = $gv->gameVersion;
                $io->text(sprintf('Game version: %s (engine %s%s)', $gameVersion,
                    $gv->engine ?? 'auto', $gv->changelist !== null ? ', CL ' . $gv->changelist : ''));
                $store->setGameVersion($branch, $gameVersion);
                $store->setRunGameVersion($runId, $gameVersion);
            } else {
                $io->warning('Could not read game version file — continuing with build id + engine auto-detect.');
            }

            // --- Component C: locate + hash the Docs file, diff vs state ---
            $docsFile = $docs->locate($installDir);
            $docsHash = $docs->hash($docsFile);
            $lastDocsHash = $store->getLastDocsHash($branch);
            $io->text('Docs: ' . basename($docsFile) . ' sha256=' . substr($docsHash, 0, 12) . '…');

            if ($docsHash === $lastDocsHash && !$force) {
                $io->text('Docs unchanged since last processed build — advancing build id only.');
                $store->setBuildId($branch, $currentBuild);
                $store->finishRun($runId, 'completed', 'build advanced, docs unchanged');

                return Command::SUCCESS;
            }

            // Archive the docs file for this build (immutable record).
            $artifactDocsDir = $this->config->dataPath('artifacts', $branch, $currentBuild, 'docs');
            if (!is_dir($artifactDocsDir) && !mkdir($artifactDocsDir, 0o775, true) && !is_dir($artifactDocsDir)) {
                throw new \RuntimeException("Cannot create artifacts dir: {$artifactDocsDir}");
            }
            copy($docsFile, $artifactDocsDir . '/' . basename($docsFile));

            $io->success('Docs changed — archived to ' . $artifactDocsDir);

            // --- Components D–G: parse each variant, extract the UNION of icons
            // ONCE, then publish + rewrite + place per variant. The ficsmas variant
            // is a near-superset of default, so a single union extraction avoids a
            // second ~9s pass over near-identical icons. ---
            $engineCandidates = null;
            if ($gv?->engine !== null) {
                $configCandidates = (array) $this->config->get('extraction.engine_candidates', []);
                $engineCandidates = array_values(array_unique([$gv->engine, ...$configCandidates]));
            }
            $imagesOut = rtrim((string) $this->config->require('publish.api_data_dir'), '/')
                . '/' . trim((string) $this->config->get('publish.images_subdir', 'images'), '/');
            $onProgress = function (string $chunk) use ($io): void {
                if ($io->isVerbose()) {
                    $io->write($chunk);
                }
            };

            // D: parse every variant; collect raw-exports + the union of image assets.
            $variantData = [];
            $union = [];
            foreach ($this->variants() as $variant) {
                $suffix = (string) ($variant['suffix'] ?? '');
                $args = array_map('strval', (array) ($variant['args'] ?? []));
                $label = $suffix === '' ? 'default' : ltrim($suffix, '-');
                $io->text("Parsing variant '{$label}'" . ($args ? ' (' . implode(' ', $args) . ')' : ''));

                $parsedDir = $this->config->dataPath('artifacts', $branch, $currentBuild, 'parsed', $label);
                $rawExport = $parser->parse($docsFile, $parsedDir, $args);
                $manifest = ImageManifest::extract($rawExport);
                ImageManifest::write($manifest, $parsedDir . '/image-manifest.json');
                foreach ($manifest as $entry) {
                    $union[$entry['assetPath']] = $entry;
                }
                $variantData[] = [
                    'label' => $label, 'suffix' => $suffix,
                    'rawExport' => $rawExport, 'assets' => \count($manifest),
                ];
            }
            $unionManifest = array_values($union);

            // E/F: extract the union of icons ONCE, then content-address + dedup.
            $imagesDir = $this->config->dataPath('artifacts', $branch, $currentBuild, 'images');
            $unionManifestPath = $imagesDir . '/image-manifest.json';
            ImageManifest::write($unionManifest, $unionManifestPath);
            $io->text(sprintf('Extracting union of %d icons across %d variant(s) → %s',
                \count($unionManifest), \count($variantData), $imagesDir));
            $result = $extractor->extract($unionManifestPath, $installDir, $imagesDir, $onProgress, $engineCandidates);
            if ($result['ok'] === 0) {
                throw new \RuntimeException('Image extraction produced 0 images — aborting (likely a pak/engine problem).');
            }
            $failRatio = $result['total'] > 0 ? $result['failed'] / $result['total'] : 0.0;
            $maxFail = (float) $this->config->get('guards.max_fail_ratio', 0.10);
            if ($result['failed'] > 0) {
                $m = sprintf('%d/%d image asset(s) failed to extract (%.1f%%).',
                    $result['failed'], $result['total'], $failRatio * 100);
                $io->warning($m . ' See extraction-result.json.');
                if ($failRatio > $maxFail) {
                    $notifier->warning("Extractor: high image failure rate ({$branch} {$currentBuild})", $m);
                }
            }
            $pub = $publisher->publish($result, $imagesDir, $imagesOut, $currentBuild);
            $io->success(sprintf('Images: %d unique, %d new, %d file(s) copied → %s',
                $pub['unique'], $pub['new'], $pub['copied'], $imagesOut));

            // G: rewrite each variant's icon refs with the shared id map, then place it.
            $summaries = [];
            foreach ($variantData as $vd) {
                $publishDir = $this->config->dataPath('artifacts', $branch, $currentBuild, 'publish', $vd['label']);
                $dataJson = $publishDir . '/data.json';
                $rw = $rewriter->rewrite($vd['rawExport'], $pub['idMap'], $dataJson);
                if ($rw['unresolved'] > 0) {
                    $io->warning(sprintf("Variant '%s': %d icon ref(s) had no image (left null).", $vd['label'], $rw['unresolved']));
                }
                $placed = $handoff->place($dataJson, $branch, $currentBuild, $vd['suffix']);
                $io->success(sprintf('%s: %d assets, %d refs → %s',
                    $vd['label'], $vd['assets'], $rw['rewritten'], basename($placed)));
                $summaries[] = sprintf('%s(%d refs)', $vd['label'], $rw['rewritten']);
            }

            // One build-level API import after all variant files exist.
            $handoff->runImport($branch, $currentBuild, $gameVersion);

            $store->setBuildId($branch, $currentBuild);
            $store->setDocsHash($branch, $docsHash);
            $store->finishRun($runId, 'completed', sprintf('%s: %d icons (%d new); variants: %s',
                $gameVersion ?? $currentBuild, $pub['unique'], $pub['new'], implode(', ', $summaries)));
            $io->success("Build {$currentBuild} for {$branch} complete.");
            $notifier->success(
                sprintf('Extractor: %s updated to %s (build %s)',
                    $branch, $gameVersion ?? '(unknown version)', $currentBuild),
                sprintf('%d icons (%d new). Variants: %s.', $pub['unique'], $pub['new'], implode(', ', $summaries)),
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($runId !== null) {
                try {
                    $store->finishRun($runId, 'failed', $e->getMessage());
                } catch (\Throwable) {
                    // ignore secondary failure
                }
            }
            $io->error("Run for '{$branch}' failed: " . $e->getMessage());
            $label = $gameVersion !== null ? "{$gameVersion} " : '';
            $notifier->failure("Extractor: {$branch} run failed ({$label}build {$currentBuild})", $e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Parser variants to run. Each produces its own client json, suffixed for the
     * API. Default: the main build (--no-ficsmas, no suffix) plus a "-ficsmas"
     * variant using the parser's default options (which include ficsmas data).
     *
     * @return list<array{suffix:string,args:list<string>}>
     */
    private function variants(): array
    {
        $configured = $this->config->get('parser.variants');
        if (\is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return [
            ['suffix' => '', 'args' => ['--no-ficsmas']],
            ['suffix' => '-ficsmas', 'args' => []],
        ];
    }

    private function assertFreeSpace(string $path, bool $installExists): void
    {
        // A fresh full download needs the whole client; an in-place update only
        // needs patch/temp space, so require far less when the install is present.
        $minGb = $installExists
            ? (float) $this->config->get('guards.min_free_gb_update', 10)
            : (float) $this->config->get('guards.min_free_gb', 40);
        $free = @disk_free_space($path);
        if ($free !== false && $free < $minGb * 1_000_000_000) {
            throw new \RuntimeException(sprintf(
                'Insufficient free disk space: %.1f GB free at %s, need >= %.0f GB (%s).',
                $free / 1_000_000_000, $path, $minGb, $installExists ? 'update' : 'fresh download',
            ));
        }
    }

    private function downloadWithRetry(
        SymfonyStyle $io,
        SteamDownloader $downloader,
        string $installDir,
        string $betaKey,
        int $attempts = 3,
    ): bool {
        $onProgress = function (string $chunk) use ($io): void {
            if ($io->isVerbose()) {
                $io->write($chunk);
            }
        };
        for ($attempt = 1; ; $attempt++) {
            try {
                return $downloader->update($installDir, $betaKey, $onProgress);
            } catch (\Throwable $e) {
                if ($attempt >= $attempts) {
                    throw $e;
                }
                $io->warning(sprintf('Download attempt %d/%d failed (%s) — retrying…',
                    $attempt, $attempts, $e->getMessage()));
                sleep(15 * $attempt);
            }
        }
    }
}
