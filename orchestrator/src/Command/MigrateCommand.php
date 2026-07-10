<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\State\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'db:migrate', description: 'Create/verify the MariaDB state tables')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $store = new StateStore($this->config);

        try {
            $store->migrate();
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success('State tables are ready (branch_state, cdn_image, run).');

        return Command::SUCCESS;
    }
}
