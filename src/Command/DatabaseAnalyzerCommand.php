<?php

namespace Indoctrinate\Command;

use Indoctrinate\Config\ConnectionCredentials;
use Indoctrinate\Config\Context;
use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Pdo\CapturingPdo;
use Indoctrinate\Service\Contract\ArtifactWriterInterface;
use Indoctrinate\Service\Contract\DestructiveRuleDetectorInterface;
use Indoctrinate\Service\Contract\RuleRunnerInterface;
use Indoctrinate\Service\Impact\CodeReferenceScanner;
use Indoctrinate\Service\Impact\SqlChangeParser;
use Indoctrinate\Service\RuleRunResult;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

class DatabaseAnalyzerCommand extends Command
{
    protected static $defaultName = 'analyze';

    private bool $configJustCreated = false;

    private RuleRunnerInterface $ruleRunner;

    private DestructiveRuleDetectorInterface $destructiveRuleDetector;

    private ArtifactWriterInterface $artifactWriter;

    public function __construct(
        RuleRunnerInterface $ruleRunner,
        DestructiveRuleDetectorInterface $destructiveRuleDetector,
        ArtifactWriterInterface $artifactWriter
    ) {
        parent::__construct();
        $this->ruleRunner = $ruleRunner;
        $this->destructiveRuleDetector = $destructiveRuleDetector;
        $this->artifactWriter = $artifactWriter;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Analyzes database issues (dry run by default — use --fix to apply changes)')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Apply fixes (default is dry-run analysis only)')
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Log output to file')
            ->addOption('prod', null, InputOption::VALUE_NONE, 'Prod mode (override connection from indoctrinate.php)')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'DSN, e.g. mysql://user:pass@host:3306/db')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user')
            ->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'DB password (prefer --db-pass-file)')
            ->addOption('db-pass-file', null, InputOption::VALUE_OPTIONAL, 'Path to file containing DB password')
            ->addOption('report', null, InputOption::VALUE_NONE, 'Output a summary table of findings per rule (exits non-zero if any findings)')
            ->addOption('sql-dump', null, InputOption::VALUE_OPTIONAL, 'Capture planned SQL and write to a .sql file (default: indoctrinate-<timestamp>.sql)', false)
            ->addOption('migration', null, InputOption::VALUE_OPTIONAL, 'Capture planned SQL and write a Doctrine migration class (default dir: migrations/)', false)
            ->addOption('impact', null, InputOption::VALUE_OPTIONAL, 'Scan a source directory for code references that will break (default: src/)', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDry = ! (bool) $input->getOption('fix');
        $isReport = (bool) $input->getOption('report');
        $sqlDumpOption = $input->getOption('sql-dump');
        $migrationOption = $input->getOption('migration');
        $impactOption = $input->getOption('impact');
        $isSqlDump = $sqlDumpOption !== false;
        $isMigration = $migrationOption !== false;
        $isImpact = $impactOption !== false;
        $isCapturing = $isSqlDump || $isMigration || $isImpact;

        if ($isCapturing && $isReport) {
            $io->error('--sql-dump / --migration / --impact cannot be combined with --report.');
            return Command::FAILURE;
        }

        $logDir = $input->getOption('log');
        $isProd = (bool) $input->getOption('prod');
        $dsn = (string) ($input->getOption('dsn') ?? '');
        $filesystem = new Filesystem();

        // ── Config ───────────────────────────────────────────────────────────

        $configFilePath = getcwd() . '/indoctrinate.php';
        $distFilePath = getcwd() . '/indoctrinate.dist.php';

        $ensureStatus = $this->ensureConfigurationFile($filesystem, $io, $configFilePath, $distFilePath, $isProd);
        if ($ensureStatus !== Command::SUCCESS) {
            return $ensureStatus;
        }
        if ($this->configJustCreated) {
            $io->success("Configuration file created at {$configFilePath}. Not running any rules this time. Re-run the command after updating the configuration file.");
            return Command::SUCCESS;
        }

        $config = new IndoctrinateConfig();
        $config->setContext(new Context($isDry, $isProd, $logDir, $configFilePath, $dsn));

        $loader = require $configFilePath;
        if (! is_callable($loader)) {
            $io->error("File $configFilePath must return a callable that accepts IndoctrinateConfig.");
            return Command::FAILURE;
        }
        $loader($config);

        if ($config->getConnectionCredentials() === null) {
            throw new RuntimeException(
                'No connection configured. Add $config->connection(...) in indoctrinate.php or run with --prod and --dsn/--db-* flags.'
            );
        }

        $credentials = $isProd
            ? $this->resolveCredentials($input, $config)
            : $config->getConnectionCredentials();

        if ($isProd) {
            $io->note('Prod mode: ignoring credentials defined in indoctrinate.php.');
        }

        $config->setConnectionCredentials($credentials);

        // ── Logging ──────────────────────────────────────────────────────────

        $logHandle = null;
        $logPath = null;

        if (! empty($logDir)) {
            try {
                if (! $filesystem->exists($logDir)) {
                    $filesystem->mkdir($logDir);
                }
                $logPath = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'db-fixer-' . date('Y-m-d_H-i-s') . '.log';
                $logHandle = fopen($logPath, 'a');
                if (! $logHandle) {
                    throw new RuntimeException("Could not open log file for writing: $logPath");
                }
                fwrite($logHandle, '[START] DB Fix started at ' . date('Y-m-d H:i:s') . PHP_EOL);
            } catch (IOExceptionInterface $e) {
                $io->error('Failed to prepare log file: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // ── Connect ──────────────────────────────────────────────────────────

        $capturingPdo = null;

        try {
            $pdoOptions = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ];

            if ($isCapturing) {
                $capturingPdo = new CapturingPdo(
                    $config->getDsn(),
                    $credentials->getUser(),
                    $credentials->getPassword(),
                    $pdoOptions
                );
                $pdo = $capturingPdo;
            } else {
                $pdo = new \PDO(
                    $config->getDsn(),
                    $credentials->getUser(),
                    $credentials->getPassword(),
                    $pdoOptions
                );
            }
        } catch (\PDOException $e) {
            $io->error('Could not connect to database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Connected to database.');
        $io->newLine();

        $activeDriver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // ── Destructive pre-flight ────────────────────────────────────────────

        if (! $isDry && ! $isReport && ! $isCapturing) {
            $destructiveRules = $this->destructiveRuleDetector->collect(
                $config->getSets(),
                $config->getRules(),
                $activeDriver
            );

            if ($destructiveRules !== []) {
                $lines = ['The following rules will execute DESTRUCTIVE operations (e.g. DROP COLUMN, DROP PRIMARY KEY, DROP FOREIGN KEY):', ''];
                foreach ($destructiveRules as $r) {
                    $lines[] = "  • {$r['name']} — {$r['description']}";
                }
                $lines[] = '';
                $lines[] = 'These changes cannot be automatically reversed. Ensure you have a backup before proceeding.';

                $label = $isProd ? 'PRODUCTION — DESTRUCTIVE ACTIONS DETECTED' : 'DESTRUCTIVE ACTIONS DETECTED';
                $io->block($lines, $label, 'error', ' ', true);

                if (! $io->confirm('Do you want to proceed with these destructive operations?', false)) {
                    $io->warning('Aborted: destructive operations cancelled by user.');
                    if ($logHandle) {
                        fwrite($logHandle, '[ABORTED] User cancelled destructive operations at ' . date('Y-m-d H:i:s') . PHP_EOL);
                        fclose($logHandle);
                    }
                    return Command::FAILURE;
                }

                $io->newLine();

                $threshold = $config->getDestructiveThreshold();
                $io->writeln('<info>Running discovery pass to assess scale of destructive changes…</info>');
                $discoveryLogs = $this->destructiveRuleDetector->discover($pdo, $config, $activeDriver);
                $discoveryCount = count($discoveryLogs);
                $io->writeln(sprintf('<info>Discovery complete: %d schema object(s) would be affected.</info>', $discoveryCount));
                $io->newLine();

                if ($discoveryCount >= $threshold) {
                    $byRule = [];
                    foreach ($discoveryLogs as $log) {
                        $byRule[$log->getRule()][] = $log->getTable() . ($log->getColumn() !== '' ? '.' . $log->getColumn() : '');
                    }

                    $volumeLines = [sprintf('Discovery found %d schema object(s) that will be modified by destructive rules (threshold: %d).', $discoveryCount, $threshold), ''];
                    foreach ($byRule as $rule => $items) {
                        $volumeLines[] = sprintf('  [%s] %d item(s):', $rule, count($items));
                        foreach (array_slice($items, 0, 10) as $item) {
                            $volumeLines[] = "    • {$item}";
                        }
                        if (count($items) > 10) {
                            $volumeLines[] = sprintf('    … and %d more', count($items) - 10);
                        }
                    }
                    $volumeLines[] = '';
                    $volumeLines[] = 'This is a large-scale change. Verify this is intentional before proceeding.';

                    $label = $isProd ? 'PRODUCTION — HIGH VOLUME DESTRUCTIVE CHANGE' : 'HIGH VOLUME DESTRUCTIVE CHANGE';
                    $io->block($volumeLines, $label, 'error', ' ', true);

                    if (! $io->confirm(sprintf('Proceed with %d destructive change(s)?', $discoveryCount), false)) {
                        $io->warning('Aborted: high-volume destructive operation cancelled by user.');
                        if ($logHandle) {
                            fwrite($logHandle, "[ABORTED] User cancelled high-volume destructive operation ({$discoveryCount} items) at " . date('Y-m-d H:i:s') . PHP_EOL);
                            fclose($logHandle);
                        }
                        return Command::FAILURE;
                    }

                    $io->newLine();
                }
            }
        }

        // ── Run analysis ─────────────────────────────────────────────────────

        $logger = fn (string $msg) => $this->logMessage($logHandle, $msg);

        $result = $this->ruleRunner->run(
            $pdo,
            $io,
            $config,
            $activeDriver,
            $isDry,
            $isCapturing,
            $isReport,
            $logger
        );

        // ── Report table ──────────────────────────────────────────────────────

        if ($isReport && $result->reportRows !== []) {
            $this->renderReportTable($output, $result->reportRows);
        }

        // ── Close log ─────────────────────────────────────────────────────────

        if ($logHandle) {
            fwrite($logHandle, '[END] DB Fix completed at ' . date('Y-m-d H:i:s') . PHP_EOL);
            fclose($logHandle);
            if ($logPath) {
                $io->note("Log written to: $logPath");
            }
        }

        // ── Report exit ───────────────────────────────────────────────────────

        if ($isReport) {
            $totalFindings = array_sum(array_column($result->reportRows, 'count'));
            if ($totalFindings > 0) {
                $io->error("Report: {$totalFindings} finding(s) detected. Exiting with failure for CI.");
                return Command::FAILURE;
            }
            $io->success('Report: No findings detected.');
            return Command::SUCCESS;
        }

        // ── Capturing output ──────────────────────────────────────────────────

        if ($isCapturing) {
            return $this->writeCapturingOutput(
                $capturingPdo,
                $result,
                $config,
                $isSqlDump,
                $isMigration,
                $sqlDumpOption,
                $migrationOption,
                $isImpact,
                $impactOption,
                $io
            );
        }

        // ── Done ──────────────────────────────────────────────────────────────

        if ($config->getSets() === [] && $config->getRules() === []) {
            $io->warning('No rules or sets registered.');
        } else {
            $io->success('Analysis complete.');
        }

        return Command::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param array<array{name: string, count: int, group: string}> $rows
     */
    private function renderReportTable(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['Rule', 'Group', 'Findings', 'Status']);
        $table->setStyle('box');

        $currentGroup = null;
        foreach ($rows as $row) {
            if ($currentGroup !== null && $currentGroup !== $row['group']) {
                $table->addRow(new TableSeparator());
            }
            $currentGroup = $row['group'];
            $status = $row['count'] === 0 ? '<info>OK</info>' : '<comment>WARN</comment>';
            $table->addRow([$row['name'], $row['group'], $row['count'], $status]);
        }

        $total = array_sum(array_column($rows, 'count'));
        $table->addRow(new TableSeparator());
        $table->addRow([new TableCell('<options=bold>TOTAL</>', [
            'colspan' => 2,
        ]), new TableCell("<options=bold>{$total}</>"), '']);
        $table->render();
        $output->writeln('');
    }

    /**
     * @param mixed $sqlDumpOption
     * @param mixed $migrationOption
     * @param mixed $impactOption
     */
    private function writeCapturingOutput(
        ?CapturingPdo $capturingPdo,
        RuleRunResult $result,
        IndoctrinateConfig $config,
        bool $isSqlDump,
        bool $isMigration,
        $sqlDumpOption,
        $migrationOption,
        bool $isImpact,
        $impactOption,
        SymfonyStyle $io
    ): int {
        $fromPdo = $capturingPdo ? $capturingPdo->getCapturedSql() : [];
        $allSql = array_values(array_unique(array_merge($fromPdo, $result->capturedSql)));
        $root = $config->getProjectRootDir();

        if ($isSqlDump) {
            $dumpPath = is_string($sqlDumpOption) && $sqlDumpOption !== ''
                ? $sqlDumpOption
                : $root . '/indoctrinate-' . date('Y-m-d_H-i-s') . '.sql';
            $this->artifactWriter->writeSqlDump($allSql, $dumpPath, $io);
        }

        if ($isMigration) {
            $migrationDir = is_string($migrationOption) && $migrationOption !== ''
                ? $migrationOption
                : $root . '/migrations';
            $this->artifactWriter->writeMigrationClass($allSql, $migrationDir, $io);
        }

        if ($isImpact) {
            $rawSourceDir = is_string($impactOption) && $impactOption !== ''
                ? $impactOption
                : $root . '/src';
            $sourceDir = realpath($rawSourceDir) ?: $rawSourceDir;

            if (! is_dir($sourceDir)) {
                $io->warning(sprintf(
                    'Impact source directory not found: %s — no code references could be scanned.',
                    $sourceDir
                ));
            }

            $changes = (new SqlChangeParser())->parse($allSql);
            $scanResult = (new CodeReferenceScanner())->scan($sourceDir, $changes);
            $this->renderImpactReport($scanResult['findings'], $scanResult['filesScanned'], $sourceDir, $io);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array{change: array<string, mixed>, references: list<array{file: string, line: int, content: string}>}> $findings
     */
    private function renderImpactReport(array $findings, int $filesScanned, string $sourceDir, SymfonyStyle $io): void
    {
        $io->section(sprintf(
            'Code Impact Analysis — %s/  (%d PHP file(s) scanned)',
            rtrim($sourceDir, '/'),
            $filesScanned
        ));

        if ($findings === []) {
            $io->success('No schema changes detected — nothing to scan.');
            return;
        }

        $bySeverity = [
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        foreach ($findings as $finding) {
            $sev = (string) ($finding['change']['severity'] ?? 'low');
            $bySeverity[$sev][] = $finding;
        }

        $severityLabel = [
            'high' => '<error> HIGH </error>',
            'medium' => '<comment> MEDIUM </comment>',
            'low' => '<info> LOW </info>',
        ];

        $totalRefs = 0;
        $affectedFiles = [];

        foreach (['high', 'medium', 'low'] as $sev) {
            foreach ($bySeverity[$sev] as $finding) {
                $change = $finding['change'];
                $refs = $finding['references'];

                $io->writeln($severityLabel[$sev] . '  ' . $this->formatChangeSummary($change));

                if ($refs === []) {
                    $io->writeln('         No references found.');
                } else {
                    $cwd = getcwd() . '/';
                    foreach ($refs as $ref) {
                        $totalRefs++;
                        $affectedFiles[$ref['file']] = true;
                        $short = str_replace($cwd, '', $ref['file']);
                        $io->writeln(sprintf('         <comment>%s:%d</comment>', $short, $ref['line']));
                        $io->writeln(sprintf('           %s', $ref['content']));
                    }
                }

                $io->newLine();
            }
        }

        $io->writeln(str_repeat('─', 60));

        if ($totalRefs === 0) {
            $io->success('Impact analysis complete — no code references found for the planned changes.');
        } else {
            $io->warning(sprintf(
                '%d reference(s) across %d file(s) to review before applying --fix.',
                $totalRefs,
                count($affectedFiles)
            ));
        }
    }

    /**
     * @param array<string, mixed> $change
     */
    private function formatChangeSummary(array $change): string
    {
        $table = (string) ($change['table'] ?? '');
        $col = (string) ($change['column'] ?? '');
        $type = (string) ($change['dataType'] ?? '');

        switch ($change['type'] ?? '') {
            case 'rename_column':
                return sprintf('%s.<options=bold>%s</> → <options=bold>%s</>  (%s)', $table, $col, $change['newColumn'] ?? '?', $type);
            case 'drop_column':
                return sprintf('%s.<options=bold>%s</>  <error>DROP</error>', $table, $col);
            case 'modify_column':
                return sprintf('%s.<options=bold>%s</>  type → %s', $table, $col, $type);
            case 'add_column':
                return sprintf('%s.<options=bold>%s</>  <info>ADD</info> %s  (new column)', $table, $col, $type);
            default:
                return sprintf('%s.%s', $table, $col);
        }
    }

    private function ensureConfigurationFile(Filesystem $filesystem, SymfonyStyle $io, string $configFilePath, string $distFilePath, bool $isProd): int
    {
        if (is_file($configFilePath)) {
            return Command::SUCCESS;
        }

        if ($isProd) {
            $io->error("Configuration file not found: $configFilePath. In --prod, the file must exist so rules can be loaded. Copy $distFilePath and adjust it, then rerun.");
            return Command::FAILURE;
        }

        $io->warning("Configuration file not found: $configFilePath");
        if (! $io->confirm("Do you want to generate it from $distFilePath?", true)) {
            $io->error('Aborted: Configuration file is required.');
            return Command::FAILURE;
        }

        if (! is_file($distFilePath)) {
            $io->error("Distribution file not found: $distFilePath");
            return Command::FAILURE;
        }

        try {
            $filesystem->copy($distFilePath, $configFilePath, true);
            $this->configJustCreated = true;
            $io->success("File $configFilePath has been generated.");
            return Command::SUCCESS;
        } catch (IOExceptionInterface $e) {
            $io->error("Failed to generate $configFilePath: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @param resource|null $logHandle
     */
    private function logMessage($logHandle, string $message): void
    {
        if ($logHandle) {
            fwrite($logHandle, $message . PHP_EOL);
        }
    }

    private function resolveCredentials(InputInterface $input, IndoctrinateConfig $config): ConnectionCredentials
    {
        if (! $config->getContext()->isProd()) {
            $connectionCredentials = $config->getConnectionCredentials();
            if (! $connectionCredentials instanceof ConnectionCredentials) {
                throw new RuntimeException(
                    'No connection configured. Add $config->connection(...) in indoctrinate.php or use --prod.'
                );
            }
            return $connectionCredentials;
        }

        if (! in_array($config->getContext()->getDsn(), [null, '', '0'], true)) {
            return $this->credentialsFromDsn($config->getContext()->getDsn());
        }

        $host = (string) ($input->getOption('db-host') ?? '');
        $name = (string) ($input->getOption('db-name') ?? '');
        $user = (string) ($input->getOption('db-user') ?? '');

        if ($host === '' || $name === '' || $user === '') {
            throw new \InvalidArgumentException(
                'In --prod, provide --dsn OR --db-host --db-name --db-user [--db-pass|--db-pass-file] [--db-port].'
            );
        }

        $passFile = $input->getOption('db-pass-file');
        $pass = (string) ($input->getOption('db-pass') ?? '');

        if ($passFile) {
            if (! is_readable($passFile)) {
                throw new RuntimeException('Cannot read --db-pass-file');
            }
            $contents = @file_get_contents($passFile);
            if ($contents === false) {
                throw new RuntimeException('Failed to read --db-pass-file');
            }
            $pass = trim($contents);
        }

        return new ConnectionCredentials(
            'mysql',
            $host,
            (int) ($input->getOption('db-port') ?: '3306'),
            $name,
            $user,
            $pass
        );
    }

    private function credentialsFromDsn(string $dsn): ConnectionCredentials
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid --dsn (expected scheme://user:pass@host:port/database)');
        }

        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($database === '') {
            throw new \InvalidArgumentException('Missing database name in --dsn (…/database at the end)');
        }

        return new ConnectionCredentials(
            rtrim((string) ($parts['scheme'] ?? 'mysql'), ':/'),
            (string) ($parts['host'] ?? '127.0.0.1'),
            (int) ($parts['port'] ?? 3306),
            $database,
            isset($parts['user']) ? urldecode($parts['user']) : '',
            isset($parts['pass']) ? urldecode($parts['pass']) : ''
        );
    }
}
