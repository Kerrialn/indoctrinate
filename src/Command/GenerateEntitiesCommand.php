<?php

declare(strict_types=1);

namespace Indoctrinate\Command;

use Indoctrinate\Config\Context;
use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Service\Contract\EntityBuilderServiceInterface;
use Indoctrinate\Service\Contract\SchemaDiscoveryServiceInterface;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateEntitiesCommand extends Command
{
    protected static $defaultName = 'entities';

    private SchemaDiscoveryServiceInterface $schemaDiscovery;

    private EntityBuilderServiceInterface $entityBuilder;

    public function __construct(SchemaDiscoveryServiceInterface $schemaDiscovery, EntityBuilderServiceInterface $entityBuilder)
    {
        parent::__construct();
        $this->schemaDiscovery = $schemaDiscovery;
        $this->entityBuilder = $entityBuilder;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generates Doctrine entity classes from the database schema')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'src/Entity')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Entity namespace', 'App\\Entity')
            ->addOption('table', 't', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only generate for these tables (repeatable)')
            ->addOption('skip-table', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Skip tables matching these SQL LIKE patterns (repeatable)')
            ->addOption('annotations', null, InputOption::VALUE_NONE, 'Force Doctrine annotations (default: auto-detect from PHP version)')
            ->addOption('attributes', null, InputOption::VALUE_NONE, 'Force PHP 8 attributes')
            ->addOption('remove-naming-prefix', null, InputOption::VALUE_REQUIRED, 'Strip a prefix from table names when deriving class names (e.g. "default" turns "default_users" into "Users")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputDir = rtrim((string) $input->getOption('output'), '/');
        $namespace = rtrim((string) $input->getOption('namespace'), '\\');
        $onlyTables = (array) $input->getOption('table');
        $skipPatterns = (array) $input->getOption('skip-table');
        $forceAnnotations = (bool) $input->getOption('annotations');
        $forceAttributes = (bool) $input->getOption('attributes');
        $removePrefix = (string) $input->getOption('remove-naming-prefix');

        if ($forceAnnotations && $forceAttributes) {
            $io->error('--annotations and --attributes are mutually exclusive.');
            return Command::FAILURE;
        }

        $useAttributes = $forceAttributes || (! $forceAnnotations && PHP_MAJOR_VERSION >= 8);
        $io->writeln(sprintf('<info>Mapping style:</info> %s (PHP %s)', $useAttributes ? 'Attributes' : 'Annotations', PHP_VERSION));
        $io->newLine();

        $configFilePath = getcwd() . '/indoctrinate.php';
        if (! is_file($configFilePath)) {
            $io->error("Configuration file not found: {$configFilePath}. Run `php bin/indoctrinate analyze` to create it.");
            return Command::FAILURE;
        }

        $config = new IndoctrinateConfig();
        $config->setContext(new Context(true, false, null, $configFilePath));

        $loader = require $configFilePath;
        if (! is_callable($loader)) {
            $io->error("File {$configFilePath} must return a callable.");
            return Command::FAILURE;
        }
        $loader($config);

        if ($config->getConnectionCredentials() === null) {
            throw new RuntimeException('No connection configured in indoctrinate.php.');
        }

        $credentials = $config->getConnectionCredentials();

        try {
            $pdo = new PDO(
                $config->getDsn(),
                $credentials->getUser(),
                $credentials->getPassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]
            );
        } catch (\PDOException $e) {
            $io->error('Could not connect to database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Connected to database.');

        $tables = $this->schemaDiscovery->discoverTables($pdo, $onlyTables, $skipPatterns);
        if ($tables === []) {
            $io->warning('No tables found matching the given criteria.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> table(s). Writing entities to <info>%s/</info>', count($tables), $outputDir));
        $io->newLine();

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $generated = 0;
        $skipped = 0;

        foreach ($tables as $tableName) {
            $className = $this->toPascalCase($this->stripPrefix($tableName, $removePrefix));
            $filePath = $outputDir . '/' . $className . '.php';

            if (file_exists($filePath)) {
                $io->writeln(sprintf('  <comment>SKIP</comment>  %s (already exists)', $filePath));
                $skipped++;
                continue;
            }

            $columns = $this->schemaDiscovery->getColumns($pdo, $tableName);
            $foreignKeys = $this->schemaDiscovery->getForeignKeys($pdo, $tableName);
            $uniqueColumns = $this->schemaDiscovery->getUniqueColumns($pdo, $tableName);

            $fkMap = [];
            foreach ($foreignKeys as $fk) {
                $fkMap[$fk['COLUMN_NAME']] = $fk;
            }

            $content = $this->entityBuilder->buildFileContent($className, $tableName, $namespace, $columns, $fkMap, $uniqueColumns, $useAttributes);
            file_put_contents($filePath, $content);
            $io->writeln(sprintf('  <info>OK</info>    %s', $filePath));
            $generated++;
        }

        $io->newLine();
        $io->success(sprintf('Generated %d entity file(s). %d skipped (already exist).', $generated, $skipped));

        return Command::SUCCESS;
    }

    private function toPascalCase(string $input): string
    {
        return str_replace('_', '', ucwords($input, '_'));
    }

    private function stripPrefix(string $tableName, string $prefix): string
    {
        if ($prefix === '') {
            return $tableName;
        }

        $normalizedPrefix = rtrim(strtolower($prefix), '_');

        // Try prefix + underscore separator first (e.g. "default_users" → "users")
        if (stripos($tableName, $normalizedPrefix . '_') === 0) {
            return substr($tableName, strlen($normalizedPrefix) + 1);
        }

        // Fall back to bare prefix (e.g. "defaultusers" → "users")
        if (stripos($tableName, $normalizedPrefix) === 0) {
            return substr($tableName, strlen($normalizedPrefix));
        }

        return $tableName;
    }
}
