<?php

namespace Indoctrinate\Command;

use Indoctrinate\Config\ConnectionCredentials;
use Indoctrinate\Config\Context;
use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Set\Contract\SetInterface;
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
    protected static $defaultName = 'fix';

    private ?IndoctrinateConfig $config = null;

    private bool $configJustCreated = false;

    protected function configure(): void
    {
        $this
            ->setDescription('Analyzes & fixes issues in database configuration')
            ->addOption('dry', null, InputOption::VALUE_NONE, 'Analyze without fixes')
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Log output to file')
            ->addOption('prod', null, InputOption::VALUE_NONE, 'Prod mode (override connection from indoctrinate.php)')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'DSN, e.g. mysql://user:pass@host:3306/db')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user')
            ->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'DB password (prefer --db-pass-file)')
            ->addOption('db-pass-file', null, InputOption::VALUE_OPTIONAL, 'Path to file containing DB password')
            ->addOption('report', null, InputOption::VALUE_NONE, 'Output a summary table of findings per rule (exits non-zero if any findings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDry = (bool) $input->getOption('dry');
        $isReport = (bool) $input->getOption('report');
        $logDir = $input->getOption('log');
        $isProd = (bool) $input->getOption('prod');
        $logHandle = null;
        $logPath = null;
        $filesystem = new Filesystem();
        $dsn = (string) ($input->getOption('dsn') ?? '');

        $configFilePath = getcwd() . '/indoctrinate.php';
        $distFilePath = getcwd() . '/indoctrinate.dist.php';

        // Ensure config file exists (and STOP if we just created it)
        $ensureStatus = $this->ensureConfigurationFile($filesystem, $io, $configFilePath, $distFilePath, $isProd);
        if ($ensureStatus !== Command::SUCCESS) {
            return $ensureStatus;
        }
        if ($this->configJustCreated) {
            $io->success("Configuration file created at {$configFilePath}. Not running any rules this time. Re-run the command after updating the configuration file.");
            return Command::SUCCESS;
        }

        // inside execute()

// Load config
        $this->config = new IndoctrinateConfig();
        $context = new Context($isDry, $isProd, $logDir, $configFilePath, $dsn);
        $this->config->setContext($context);

        $loader = require $configFilePath;
        if (! is_callable($loader)) {
            $io->error("File $configFilePath must return a callable that accepts IndoctrinateConfig.");
            return Command::FAILURE;
        }
        $loader($this->config);
        if ($this->config->getConnectionCredentials() === null) {
            throw new RuntimeException(
                'No connection configured. Add $config->connection(...) in indoctrinate.php or run with --prod and --dsn/--db-* flags.'
            );
        }

        $credentials = $isProd
            ? $this->resolveCredentials($input, $this->config)
            : ($this->config->getConnectionCredentials());

        if ($isProd && $this->config->getConnectionCredentials() instanceof \Indoctrinate\Config\ConnectionCredentials) {
            $io->note('Prod mode: ignoring credentials defined in indoctrinate.php.');
        }

        $this->config->setConnectionCredentials($credentials);

        if (! empty($logDir)) {
            try {
                if (! $filesystem->exists($logDir)) {
                    $filesystem->mkdir($logDir);
                }
                $timestamp = date('Y-m-d_H-i-s');
                $logFilename = "db-fixer-{$timestamp}.log";
                $logPath = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . $logFilename;

                $logHandle = fopen($logPath, 'a');
                if (! $logHandle) {
                    throw new RuntimeException("Could not open log file for writing: $logPath");
                }
                fwrite($logHandle, "[START] DB Fix started at " . date('Y-m-d H:i:s') . PHP_EOL);
            } catch (IOExceptionInterface $e) {
                $io->error("Failed to prepare log file: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Connect
        try {
            $pdo = new \PDO(
                $this->config->getDsn(),
                $credentials->getUser(),
                $credentials->getPassword(),
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]
            );
        } catch (\PDOException $e) {
            $io->error("Could not connect to database: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Connected to database.");
        $io->newLine();

        $activeDriver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // ===================== RUN SETS (with per-rule constraints) ==================
        /** @var array<array{name: string, count: int, group: string}> $reportRows */
        $reportRows = [];

        $sets = $this->config->getSets();

        if ($sets !== []) {
            foreach ($sets as $class => $rulesConfiguration) {

                if (! is_string($class) || ! class_exists($class)) {
                    $msg = "Set class not found: {$class}";
                    $io->warning($msg);
                    $this->logMessage($logHandle, $msg);
                    continue;
                }

                /** @var SetInterface $set */
                $set = new $class();
                if (! $set instanceof SetInterface) {
                    $msg = "Set {$class} must implement SetInterface.";
                    $io->warning($msg);
                    $this->logMessage($logHandle, $msg);
                    continue;
                }

                $incompatible = array_filter($set->getRules(), fn (string $r) => $r::getDriver() !== $activeDriver);
                if ($incompatible !== []) {
                    $msg = "Skipping set {$class}: contains rules incompatible with driver '{$activeDriver}'.";
                    $io->warning($msg);
                    $this->logMessage($logHandle, $msg);
                    continue;
                }

                $title = "Running set: " . $set->getName() . " — " . $set->getDescription();
                $io->section($title);
                $this->logMessage($logHandle, $title);

                $set->config(is_array($rulesConfiguration) ? $rulesConfiguration : []);

                // Pass your map straight through; the set will pick constraints by Rule FQCN.
                $setContext = [
                    'dry' => $this->config->getContext()->isDry(),
                ];

                try {
                    $logs = $set->execute($pdo, $io, $setContext);
                    $issueCount = is_array($logs) ? count($logs) : 0;

                    // Group logs by rule name for report mode
                    $countByRuleName = [];
                    foreach ($logs as $log) {
                        $countByRuleName[$log->getRule()] = ($countByRuleName[$log->getRule()] ?? 0) + 1;
                    }
                    foreach ($set->getRules() as $ruleClass) {
                        $ruleName = $ruleClass::getName();
                        $reportRows[] = [
                            'name' => $ruleName,
                            'count' => $countByRuleName[$ruleName] ?? 0,
                            'group' => $set->getName(),
                        ];
                    }

                    if ($isReport) {
                        $this->logMessage($logHandle, "Set {$class}: {$issueCount} total findings");
                    } elseif ($issueCount === 0) {
                        $io->success("✔ {$class}" . ($isDry ? ' (dry run)' : ''));
                        $this->logMessage($logHandle, "No findings from set {$class}");
                    } else {
                        foreach ($logs as $log) {
                            $msg = $log->getMessage();
                            $this->logMessage($logHandle, $msg);
                            $io->warning($msg);
                        }
                        $io->note("Findings: {$issueCount}");
                        $this->logMessage($logHandle, "Findings: {$issueCount}");
                    }
                } catch (\Throwable $e) {
                    $error = "Exception during set {$class}: " . $e->getMessage();
                    $io->error($error);
                    $this->logMessage($logHandle, "✘ {$class} failed: " . $e->getMessage());
                }

                if (! $isReport) {
                    $io->writeln(str_repeat('-', 80));
                }
            }
            $io->newLine(2);
        } else {
            $io->note('No sets registered.');
        }

        /** @var array<int, array{class:string, RuleConstraintInterface}> $rules */
        $rules = $this->config->getRules();

        if (empty($rules)) {
            if ($isReport && $reportRows !== []) {
                $this->renderReportTable($output, $reportRows);
            }
            if ($logHandle) {
                fwrite($logHandle, "[END] DB Fix completed at " . date('Y-m-d H:i:s') . PHP_EOL);
                fclose($logHandle);
                if ($logPath) {
                    $io->note("Log written to: $logPath");
                }
            }
            if ($isReport) {
                $totalFindings = array_sum(array_column($reportRows, 'count'));
                if ($totalFindings > 0) {
                    $io->error("Report: {$totalFindings} finding(s) detected. Exiting with failure for CI.");
                    return Command::FAILURE;
                }
                $io->success('Report: No findings detected.');
                return Command::SUCCESS;
            }
            $io->warning("No rules registered.");
            $io->success("All rules executed.");
            return Command::SUCCESS;
        }

        foreach ($rules as $key => $def) {
            $ruleClass = null;
            $constraintsObj = null;
            $inlineContext = [];

            // 1) Plain string: RuleClass::class
            if (is_string($def) && class_exists($def)) {
                $ruleClass = $def;

                // 2) Map form: RuleClass::class => ConstraintObj
            } elseif ($def instanceof RuleConstraintInterface && is_string($key) && class_exists($key)) {
                $ruleClass = $key;
                $constraintsObj = $def;

                // 3) Array forms
            } elseif (is_array($def)) {
                // 3a) ['class' => FQCN, 'constraints' => obj] (optional 'context' => array)
                if (isset($def['class']) && is_string($def['class'])) {
                    $ruleClass = $def['class'];
                    if (isset($def['constraints']) && $def['constraints'] instanceof RuleConstraintInterface) {
                        $constraintsObj = $def['constraints'];
                    }
                    if (isset($def['context']) && is_array($def['context'])) {
                        $inlineContext = $def['context'];
                    }
                    // 3b) [FQCN, constraintsOrContext]
                } elseif (isset($def[0]) && is_string($def[0])) {
                    $ruleClass = $def[0];
                    if (isset($def[1]) && $def[1] instanceof RuleConstraintInterface) {
                        $constraintsObj = $def[1];
                    } elseif (isset($def[1]) && is_array($def[1])) {
                        $inlineContext = $def[1];
                    }
                    // 3c) Map form: RuleClass::class => ['…context…']
                } elseif (is_string($key) && class_exists($key)) {
                    $ruleClass = $key;
                    $inlineContext = $def;
                }

                // 4) Map form: RuleClass::class => true/null (enable with no constraints)
            } elseif (is_string($key) && class_exists($key)) {
                $ruleClass = $key;
            }

            if (! $ruleClass || ! class_exists($ruleClass)) {
                $msg = 'Unrecognized rule spec; skipping.';
                $io->warning($msg);
                $this->logMessage($logHandle, $msg);
                continue;
            }

            /** @var RuleInterface $rule */
            $rule = new $ruleClass();
            if (! $rule instanceof RuleInterface) {
                $msg = "Rule {$ruleClass} does not implement RuleInterface.";
                $io->warning($msg);
                $this->logMessage($logHandle, $msg);
                continue;
            }

            if ($ruleClass::getDriver() !== $activeDriver) {
                $msg = "Skipping rule {$ruleClass}: requires driver '{$ruleClass::getDriver()}', connected to '{$activeDriver}'.";
                $io->warning($msg);
                $this->logMessage($logHandle, $msg);
                continue;
            }

            $title = "Running rule: " . $ruleClass::getName() . " [" . $ruleClass::getCategory() . "]";
            $io->section($title);
            $this->logMessage($logHandle, $title);

            // Build context: constraint → array OR inline array. CLI --dry always wins.
            $ruleContext = [];
            if ($constraintsObj instanceof RuleConstraintInterface) {
                // Your interface exposes toContext(); if it’s named differently, call that instead.
                $ruleContext = $constraintsObj->toContext();
            } elseif ($inlineContext !== []) {
                $ruleContext = $inlineContext;
            }
            $context = $ruleContext;
            $context['dry'] = $isDry; // force CLI flag

            $useTransaction = ! $ruleClass::isDestructive() && ! $isDry;

            try {
                if ($useTransaction) {
                    $pdo->beginTransaction();
                }

                $logs = $rule->apply($pdo, $io, $context);
                $issueCount = is_array($logs) ? count($logs) : 0;

                $reportRows[] = [
                    'name' => $ruleClass::getName(),
                    'count' => $issueCount,
                    'group' => 'standalone',
                ];

                if ($isReport) {
                    $this->logMessage($logHandle, "{$ruleClass}: {$issueCount} findings");
                } elseif ($issueCount === 0) {
                    $io->success('No issues found by this rule.');
                    $this->logMessage($logHandle, "No issues found by {$ruleClass}");
                } else {
                    foreach ($logs as $log) {
                        $msg = $log->getMessage();
                        $this->logMessage($logHandle, $msg);
                        $io->warning($msg);
                    }
                    $io->note("Findings: {$issueCount}");
                    $this->logMessage($logHandle, "Findings: {$issueCount}");
                }

                if (! $isReport) {
                    if ($useTransaction) {
                        $pdo->commit();
                        $io->success('Transaction committed.');
                        $this->logMessage($logHandle, "✔ {$ruleClass} committed");
                    } elseif ($isDry) {
                        $io->note('Dry run: no schema changes were executed.');
                        $this->logMessage($logHandle, "✔ {$ruleClass} rolled back (dry run)");
                    } else {
                        $io->success('Rule finished.');
                        $this->logMessage($logHandle, "✔ {$ruleClass} applied");
                    }
                } elseif ($useTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Exception during rule {$ruleClass}: " . $e->getMessage();
                $io->error($error);
                $this->logMessage($logHandle, "✘ {$ruleClass} failed: " . $e->getMessage());
            }

            if (! $isReport) {
                $io->newLine();
                $this->logMessage($logHandle, str_repeat('-', 80));
            }
        }

        if ($isReport && $reportRows !== []) {
            $this->renderReportTable($output, $reportRows);
        }

        if ($logHandle) {
            fwrite($logHandle, "[END] DB Fix completed at " . date('Y-m-d H:i:s') . PHP_EOL);
            fclose($logHandle);
            if ($logPath) {
                $io->note("Log written to: $logPath");
            }
        }

        if ($isReport) {
            $totalFindings = array_sum(array_column($reportRows, 'count'));
            if ($totalFindings > 0) {
                $io->error("Report: {$totalFindings} finding(s) detected. Exiting with failure for CI.");
                return Command::FAILURE;
            }
            $io->success('Report: No findings detected.');
            return Command::SUCCESS;
        }

        $io->success("All rules executed.");
        return Command::SUCCESS;
    }

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
        $table->addRow([new TableCell('<options=bold>TOTAL</>', ['colspan' => 2]), new TableCell("<options=bold>{$total}</>"), '']);

        $table->render();
        $output->writeln('');
    }

    /**
     * Ensure indoctrinate.php exists. If we create it now, set $this->configJustCreated=true
     * and return SUCCESS so the caller can exit early.
     */
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
        $generate = $io->confirm("Do you want to generate it from $distFilePath?", true);

        if (! $generate) {
            $io->error("Aborted: Configuration file is required.");
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

        // Discrete options
        $host = (string) ($input->getOption('db-host') ?? null);
        $name = (string) ($input->getOption('db-name') ?? null);
        $user = (string) ($input->getOption('db-user') ?? null);

        if ($host === '' || $host === '0' || ($name === '' || $name === '0') || ($user === '' || $user === '0')) {
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

        $port = (string) ($input->getOption('db-port') ?: '3306');

        return new ConnectionCredentials(
            'mysql',
            $host,
            $port,
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

        $driver = rtrim((string) ($parts['scheme'] ?? 'mysql'), ':/');
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (string) ($parts['port'] ?? '3306');
        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        $user = isset($parts['user']) ? urldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

        if ($database === '') {
            throw new \InvalidArgumentException('Missing database name in --dsn (…/database at the end)');
        }

        return new ConnectionCredentials(
            $driver,
            $host,
            $port,
            $database,
            $user,
            $pass
        );
    }
}