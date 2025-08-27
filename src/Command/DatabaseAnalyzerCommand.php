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
    /** @var DbFixerConfig */
    private $config;

    public function __construct(DbFixerConfig $config)
    {
        $this->config = $config;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Analyzes & fixes issues in database configuration')
            // Boolean switch is more idiomatic, but we keep your signature intact
            ->addOption(name: 'dry', shortcut: InputOption::VALUE_OPTIONAL, description: 'Analyze without fixes')
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Log output to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $isDry    = (bool)$input->getOption('dry');
        $logDir   = $input->getOption('log');
        $logHandle = null;
        $logPath   = null;
        $filesystem = new Filesystem();

        if (!empty($logDir)) {
            try {
                if (!$filesystem->exists($logDir)) {
                    $filesystem->mkdir($logDir);
                }

                $timestamp  = date('Y-m-d_H-i-s');
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

        if (method_exists($this->config, 'getRuleDefinitions')) {
            /** @var array<int, array{class:string, RuleConstraintInterface}> $ruleDefs */
            $ruleDefs = $this->config->getRuleDefinitions();
        } else {
            // Back-compat: just class names
            $ruleDefs = array_map(function ($class) {
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

            // Build context from constraints object + dry flag
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
                } else {
                    if ($isDry) {
                        $io->note('Dry run: no schema changes were executed.');
                        $this->logMessage($logHandle, "✔ {$ruleClass} rolled back (dry run)");
                    } else {
                        // Destructive rules run without an outer transaction (DDL auto-commits)
                        $io->success('Rule finished.');
                        $this->logMessage($logHandle, "✔ {$ruleClass} applied");
                    }
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
     * @param resource|null $logHandle
     */
    private function logMessage($logHandle, string $message): void
    {
        if ($logHandle) {
            fwrite($logHandle, $message . PHP_EOL);
        }
    }
}
