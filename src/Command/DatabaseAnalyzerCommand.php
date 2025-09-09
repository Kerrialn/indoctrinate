<?php

namespace DbFixer\Command;

use DbFixer\Config\DbFixerConfig;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use DbFixer\Rule\Contract\RuleConstraintInterface;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'fix')]
class DatabaseAnalyzerCommand extends Command
{
    /** @var DbFixerConfig|null */
    private $config = null;

    /** @var bool */
    private $configJustCreated = false;

    protected function configure(): void
    {
        $this
            ->setDescription('Analyzes & fixes issues in database configuration')
            ->addOption('dry', null, InputOption::VALUE_OPTIONAL, 'Analyze without fixes')
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Log output to file')
            ->addOption('prod', null, InputOption::VALUE_NONE, 'Prod mode (override connection from indoctrinate.php)')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'DSN, e.g. mysql://user:pass@host:3306/db')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user')
            ->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'DB password (prefer --db-pass-file)')
            ->addOption('db-pass-file', null, InputOption::VALUE_OPTIONAL, 'Path to file containing DB password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $isDry      = (bool)$input->getOption('dry');
        $logDir     = $input->getOption('log');
        $isProd     = (bool)$input->getOption('prod');
        $logHandle  = null;
        $logPath    = null;
        $filesystem = new Filesystem();

        $configFilePath = getcwd() . '/indoctrinate.php';
        $distFilePath   = getcwd() . '/indoctrinate.dist.php';

        // Ensure config file exists (and STOP if we just created it)
        $ensureStatus = $this->ensureConfigurationFile($filesystem, $io, $configFilePath, $distFilePath, $isProd);
        if ($ensureStatus !== Command::SUCCESS) {
            return $ensureStatus;
        }
        if ($this->configJustCreated) {
            $io->success("Configuration file created at {$configFilePath}. Not running any rules this time. Re-run the command after updating the configuration file.");
            return Command::SUCCESS;
        }

        $this->config = new DbFixerConfig();

        // Load rules (and maybe default connection) from indoctrinate.php
        $loader = require $configFilePath;
        if (!is_callable($loader)) {
            $io->error("File $configFilePath must return a callable that accepts DbFixerConfig.");
            return Command::FAILURE;
        }
        $loader($this->config);

        // Prod mode: override connection details via CLI flags
        if ($isProd) {
            $creds = $this->resolveProdCredentials($input, $io);
            if ($creds === null) {
                return Command::INVALID;
            }
            $this->config->connection(
                $creds['driver'],
                $creds['host'],
                $creds['port'],
                $creds['dbname'],
                $creds['user'],
                $creds['password']
            );
        }

        // Optional logfile
        if (!empty($logDir)) {
            try {
                if (!$filesystem->exists($logDir)) {
                    $filesystem->mkdir($logDir);
                }
                $timestamp   = date('Y-m-d_H-i-s');
                $logFilename = "db-fixer-{$timestamp}.log";
                $logPath     = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . $logFilename;

                $logHandle = fopen($logPath, 'a');
                if (!$logHandle) {
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
            $pdo = new PDO(
                $this->config->getDsn(),
                $this->config->getUser(),
                $this->config->getPassword(),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $io->error("Could not connect to database: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Connected to database.");
        $io->newLine();

        // Load rule definitions
        if (method_exists($this->config, 'getRuleDefinitions')) {
            /** @var array<int, array{class:string, constraints:?RuleConstraintInterface}> $ruleDefs */
            $ruleDefs = $this->config->getRuleDefinitions();
        } else {
            $ruleDefs = array_map(function (string $class): array {
                return ['class' => $class, 'constraints' => null];
            }, $this->config->getRules());
        }

        if (empty($ruleDefs)) {
            $io->warning("No rules registered.");
            return Command::SUCCESS;
        }

        foreach ($ruleDefs as $def) {
            $ruleClass      = $def['class'];
            $constraintsObj = isset($def['constraints']) ? $def['constraints'] : null;

            if (!class_exists($ruleClass)) {
                $msg = "Rule class not found: {$ruleClass}";
                $io->warning($msg);
                $this->logMessage($logHandle, $msg);
                continue;
            }

            $rule = new $ruleClass();
            if (!$rule instanceof DatabaseFixRuleInterface) {
                $msg = "Rule {$ruleClass} does not implement DatabaseFixRuleInterface.";
                $io->warning($msg);
                $this->logMessage($logHandle, $msg);
                continue;
            }

            $title = "Running rule: " . $ruleClass::getName() . " [" . $ruleClass::getCategory() . "]";
            $io->section($title);
            $this->logMessage($logHandle, $title);

            $ruleContext = ($constraintsObj instanceof RuleConstraintInterface) ? $constraintsObj->toContext() : [];
            $context     = array_merge($ruleContext, ['dry' => $isDry]);

            $useTransaction = !$ruleClass::isDestructive() && !$isDry;

            try {
                if ($useTransaction) {
                    $pdo->beginTransaction();
                }

                $logs = $rule->apply($pdo, $io, $context);
                $issueCount = is_array($logs) ? count($logs) : 0;

                if ($issueCount === 0) {
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
            } catch (\Throwable $e) {
                if ($useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Exception during rule {$ruleClass}: " . $e->getMessage();
                $io->error($error);
                $this->logMessage($logHandle, "✘ {$ruleClass} failed: " . $e->getMessage());
            }

            $io->newLine();
            $this->logMessage($logHandle, str_repeat('-', 80));
        }

        if ($logHandle) {
            fwrite($logHandle, "[END] DB Fix completed at " . date('Y-m-d H:i:s') . PHP_EOL);
            fclose($logHandle);
            if ($logPath) {
                $io->note("Log written to: $logPath");
            }
        }

        $io->success("All rules executed.");
        return Command::SUCCESS;
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

        if (!$generate) {
            $io->error("Aborted: Configuration file is required.");
            return Command::FAILURE;
        }

        if (!is_file($distFilePath)) {
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

    private function resolveProdCredentials(InputInterface $input, SymfonyStyle $io): ?array
    {
        $dsn = $input->getOption('dsn');
        if ($dsn) {
            $parts = parse_url((string)$dsn);
            if ($parts === false) {
                $io->error('Invalid --dsn (expected scheme://user:pass@host:port/dbname)');
                return null;
            }
            return [
                'driver'   => rtrim($parts['scheme'] ?? 'mysql', ':/'),
                'host'     => $parts['host'] ?? '127.0.0.1',
                'port'     => isset($parts['port']) ? (int)$parts['port'] : 3306,
                'dbname'   => ltrim($parts['path'] ?? '', '/'),
                'user'     => $parts['user'] ?? '',
                'password' => $parts['pass'] ?? '',
            ];
        }

        $host = $input->getOption('db-host');
        $name = $input->getOption('db-name');
        $user = $input->getOption('db-user');

        if (!$host || !$name || !$user) {
            $io->error('In --prod, provide --dsn OR --db-host --db-name --db-user [--db-pass|--db-pass-file] [--db-port].');
            return null;
        }

        $passFile = $input->getOption('db-pass-file');
        $pass     = (string)$input->getOption('db-pass');
        if ($passFile) {
            if (!is_readable($passFile)) {
                $io->error('Cannot read --db-pass-file');
                return null;
            }
            $pass = trim((string)file_get_contents($passFile));
        }

        return [
            'driver'   => 'mysql',
            'host'     => (string)$host,
            'port'     => (int)($input->getOption('db-port') ?: 3306),
            'dbname'   => (string)$name,
            'user'     => (string)$user,
            'password' => $pass,
        ];
    }
}
