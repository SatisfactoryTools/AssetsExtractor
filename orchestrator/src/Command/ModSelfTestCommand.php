<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use App\Mods\DocsEntryBuilder;
use App\Mods\DocsFile;
use App\Mods\ModClassExtractor;
use App\Mods\ModEnvironment;
use App\Mods\NativeDefaults;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the mod importer against the base game: every base-game blueprint class is
 * rebuilt from the paks the way mod classes are, parsed, and compared with the parse of
 * the game's own Docs file. Differences point at values the importer gets wrong.
 */
#[AsCommand(name: 'mod:selftest', description: 'Rebuild the base game\'s Docs the way mods are read and compare the parsed data')]
final class ModSelfTestCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Logical branch whose install to read', 'stable')
            ->addOption('install-dir', null, InputOption::VALUE_REQUIRED, 'Read this game install instead of the branch install')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Example differences to print per field', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installDir = (string) ($input->getOption('install-dir') ?? $this->config->dataPath('steam', (string) $input->getOption('branch')));
        $workDir = $this->config->dataPath('artifacts', 'mods', 'selftest');

        try {
            $env = ModEnvironment::create($this->config, $installDir);
            $docs = DocsFile::load($env->docsFile);
            $io->text('Reading base-game classes…');
            $dump = $env->classExtractor->extract($installDir, $workDir . '/dump', [], $docs->blueprintPackages(), $docs->blueprintClassNames(), ModClassExtractor::SINK_TABLES);
            $baseClasses = (array) ($dump['base']['classes'] ?? []);
            $defaults = NativeDefaults::infer($docs, $baseClasses);

            $builder = new DocsEntryBuilder($docs, $defaults, [], true);
            $builder->addClasses('base', $baseClasses);
            $builder->addSinkTables((array) ($dump['base']['dataTables'] ?? []));
            $built = $builder->build('base');
            $replacements = [];
            foreach ($built['entries'] as $class) {
                $replacements[(string) $class['entry']['ClassName']] = $class['entry'];
            }
            $io->text(sprintf('Rebuilt %d of %d classes; parsing both versions…', \count($replacements), \count($baseClasses)));

            $expected = $env->parse($docs, $workDir . '/expected');
            $actual = $env->parse($docs->replacing($replacements), $workDir . '/actual');
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $samples = max(0, (int) $input->getOption('samples'));
        $differences = 0;
        foreach ($expected as $category => $entries) {
            if (!\is_array($entries) || array_is_list($entries)) {
                $same = json_encode($entries) === json_encode($actual[$category] ?? null);
                if (!$same) {
                    $differences++;
                    $io->warning("{$category}: list differs");
                }
                continue;
            }

            $fields = [];
            foreach ($entries as $className => $entry) {
                $other = $actual[$category][$className] ?? null;
                if (!\is_array($other)) {
                    $fields['(missing entry)'][] = (string) $className;
                    continue;
                }
                foreach ($entry as $field => $value) {
                    if (json_encode($value) !== json_encode($other[$field] ?? null)) {
                        $fields[$field][] = sprintf('%s: expected %s, got %s', $className,
                            substr((string) json_encode($value, JSON_UNESCAPED_SLASHES), 0, 160),
                            substr((string) json_encode($other[$field] ?? null, JSON_UNESCAPED_SLASHES), 0, 160));
                    }
                }
            }

            foreach ($fields as $field => $examples) {
                $differences += \count($examples);
                $io->text(sprintf('<comment>%s.%s</comment>: %d differ', $category, $field, \count($examples)));
                foreach (\array_slice($examples, 0, $samples) as $example) {
                    $io->text('    ' . $example);
                }
            }
        }

        if ($differences === 0) {
            $io->success('The rebuilt Docs parse to exactly the same data.');

            return Command::SUCCESS;
        }

        $io->warning("{$differences} difference(s). Files: {$workDir}/expected and {$workDir}/actual.");

        return Command::FAILURE;
    }
}
