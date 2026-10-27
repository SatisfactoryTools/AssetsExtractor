<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\GameVersion;
use App\Support\RunLock;
use App\World\WorldPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-extracts the world (nodes, collectibles, map) of a branch's current install
 * and publishes it, without touching the docs/icon pipeline or the state. Used to
 * give already-published versions a map: the printed references file is what the
 * API's `world:attach` command stamps into their data files.
 */
#[AsCommand(name: 'world', description: 'Extract and publish the world data + map of an installed branch')]
final class WorldCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Logical branch whose install to read', 'stable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $branch = (string) $input->getOption('branch');
        if (!isset($this->config->branches()[$branch])) {
            $io->error("Unknown branch '{$branch}'.");

            return Command::FAILURE;
        }

        $installDir = $this->config->dataPath('steam', $branch);
        if (!is_dir($installDir . '/FactoryGame')) {
            $io->error("No install for '{$branch}' at {$installDir} — run the pipeline first.");

            return Command::FAILURE;
        }

        // Shares the run lock, so it never reads an install that steamcmd is updating.
        $lock = new RunLock($this->config->dataPath('locks', "{$branch}.lock"));
        if (!$lock->acquire()) {
            $io->error("A run for '{$branch}' is in progress.");

            return Command::FAILURE;
        }

        try {
            $gv = GameVersion::read($installDir);
            $engineCandidates = null;
            if ($gv?->engine !== null) {
                $engineCandidates = array_values(array_unique([
                    $gv->engine,
                    ...(array) $this->config->get('extraction.engine_candidates', []),
                ]));
            }
            $outDir = $this->config->dataPath('artifacts', $branch, 'world-' . ($gv->changelist ?? 'current'));

            $onProgress = function (string $chunk) use ($io): void {
                if ($io->isVerbose()) {
                    $io->write($chunk);
                }
            };
            $references = WorldPipeline::fromConfig($this->config)
                ->run($installDir, $outDir, $gv?->gameVersion, $onProgress, $engineCandidates);
        } catch (\Throwable $e) {
            $io->error('World extraction failed: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }

        $io->success(sprintf('Published map %s, %s and %s.', $references['map'], $references['worldSource'], $references['collectibles']));
        $io->text([
            'References: ' . $outDir . '/references.json',
            'Attach them to published versions with the API command:',
            '  bin/console world:attach --references ' . escapeshellarg($outDir . '/references.json') . ' <version-slug>...',
        ]);

        return Command::SUCCESS;
    }
}
