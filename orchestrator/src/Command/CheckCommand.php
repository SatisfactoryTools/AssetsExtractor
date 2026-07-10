<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\State\StateStore;
use App\Steam\SteamClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only: query Steam for the current build id of each configured branch and
 * compare with the last processed build id in state. No downloads, no writes.
 */
#[AsCommand(name: 'check', description: 'Report current vs last-processed build id per branch')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $steam = new SteamClient(
            (string) $this->config->require('steam.steamcmd'),
            (int) $this->config->require('steam.app_id'),
        );

        $io->text('Querying Steam (anonymous, no credentials)…');
        try {
            $current = $steam->getBranchBuildIds($this->config->branches());
        } catch (\Throwable $e) {
            $io->error('Steam query failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // State comparison is best-effort so `check` works before the DB exists.
        $lastById = [];
        $dbNote = '';
        try {
            $store = new StateStore($this->config);
            foreach ($current as $branch => $_) {
                $lastById[$branch] = $store->getLastBuildId($branch);
            }
        } catch (\Throwable $e) {
            $dbNote = ' (state unavailable: ' . $e->getMessage() . ')';
        }

        $rows = [];
        foreach ($current as $branch => $buildId) {
            $last = $lastById[$branch] ?? null;
            $status = match (true) {
                $buildId === null => 'branch not found',
                $dbNote !== '' => 'unknown',
                $last === null => 'NEW (never processed)',
                $last !== $buildId => 'NEW (changed)',
                default => 'up to date',
            };
            $rows[] = [$branch, $buildId ?? '—', $last ?? '—', $status];
        }

        $io->table(['branch', 'current build', 'last processed', 'status'], $rows);
        if ($dbNote !== '') {
            $io->warning('State comparison skipped' . $dbNote);
        }

        return Command::SUCCESS;
    }
}
