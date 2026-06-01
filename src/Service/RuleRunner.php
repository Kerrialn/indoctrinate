<?php

declare(strict_types=1);

namespace Indoctrinate\Service;

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Contract\BreaksExpandContractPatternInterface;
use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Service\Contract\RuleRunnerInterface;
use Indoctrinate\Set\Contract\SetInterface;
use PDO;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RuleRunner implements RuleRunnerInterface
{
    public function run(
        PDO $pdo,
        SymfonyStyle $io,
        IndoctrinateConfig $config,
        string $activeDriver,
        bool $isDry,
        bool $isCapturing,
        bool $isReport,
        callable $logger
    ): RuleRunResult {
        $result = new RuleRunResult();

        $this->runSets($pdo, $io, $config, $activeDriver, $isDry, $isCapturing, $isReport, $logger, $result);
        $this->runRules($pdo, $io, $config, $activeDriver, $isDry, $isCapturing, $isReport, $logger, $result);

        return $result;
    }

    private function runSets(
        PDO $pdo,
        SymfonyStyle $io,
        IndoctrinateConfig $config,
        string $activeDriver,
        bool $isDry,
        bool $isCapturing,
        bool $isReport,
        callable $logger,
        RuleRunResult $result
    ): void {
        $sets = $config->getSets();

        if ($sets === []) {
            $io->note('No sets registered.');
            return;
        }

        foreach ($sets as $class => $rulesConfiguration) {
            if (! is_string($class) || ! class_exists($class)) {
                $msg = "Set class not found: {$class}";
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            /** @var SetInterface $set */
            $set = new $class();
            if (! $set instanceof SetInterface) {
                $msg = "Set {$class} must implement SetInterface.";
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            $incompatible = array_filter($set->getRules(), fn (string $r) => $r::getDriver() !== $activeDriver);
            if ($incompatible !== []) {
                $msg = "Skipping set {$class}: contains rules incompatible with driver '{$activeDriver}'.";
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            $title = 'Running set: ' . $set->getName() . ' — ' . $set->getDescription();
            $io->section($title);
            $logger($title);

            $set->config(is_array($rulesConfiguration) ? $rulesConfiguration : []);

            if (! $isDry) {
                foreach ($set->getRules() as $ruleClass) {
                    if (is_a($ruleClass, BreaksExpandContractPatternInterface::class, true)) {
                        $io->warning(sprintf(
                            '%s does not follow the Expand/Contract pattern — it modifies existing columns or table properties in-place and may lock tables. Ensure you have a backup before continuing.',
                            $ruleClass::getName()
                        ));
                    }
                }
            }

            $setContext = [
                'dry' => $isCapturing ? true : $isDry,
            ];

            try {
                $logs = $set->execute($pdo, $io, $setContext);
                $issueCount = count($logs);

                if ($isCapturing) {
                    foreach ($logs as $log) {
                        if ($this->isSqlStatement($log->getTo())) {
                            $result->capturedSql[] = rtrim(trim($log->getTo()), ';');
                        }
                    }
                }

                $countByRuleName = [];
                foreach ($logs as $log) {
                    $countByRuleName[$log->getRule()] = ($countByRuleName[$log->getRule()] ?? 0) + 1;
                }
                foreach ($set->getRules() as $ruleClass) {
                    $ruleName = $ruleClass::getName();
                    $result->reportRows[] = [
                        'name' => $ruleName,
                        'count' => $countByRuleName[$ruleName] ?? 0,
                        'group' => $set->getName(),
                    ];
                }

                if ($isReport) {
                    $logger("Set {$class}: {$issueCount} total findings");
                } elseif ($issueCount === 0) {
                    $io->success("✔ {$class}" . ($isDry ? ' (dry run)' : ''));
                    $logger("No findings from set {$class}");
                } else {
                    foreach ($logs as $log) {
                        $msg = $log->getMessage();
                        $logger($msg);
                        $io->warning($msg);
                    }
                    $io->note("Findings: {$issueCount}");
                    $logger("Findings: {$issueCount}");
                }
            } catch (\Throwable $e) {
                $error = "Exception during set {$class}: " . $e->getMessage();
                $io->error($error);
                $logger("✘ {$class} failed: " . $e->getMessage());
            }

            if (! $isReport) {
                $io->writeln(str_repeat('-', 80));
            }
        }

        $io->newLine(2);
    }

    private function runRules(
        PDO $pdo,
        SymfonyStyle $io,
        IndoctrinateConfig $config,
        string $activeDriver,
        bool $isDry,
        bool $isCapturing,
        bool $isReport,
        callable $logger,
        RuleRunResult $result
    ): void {
        foreach ($config->getRules() as $key => $def) {
            $ruleClass = $this->resolveRuleClass($key, $def);
            $constraintsObj = $def instanceof RuleConstraintInterface ? $def : null;
            $inlineContext = is_array($def) ? $this->extractInlineContext($key, $def) : [];

            if ($ruleClass === null || ! class_exists($ruleClass)) {
                $msg = 'Unrecognized rule spec; skipping.';
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            /** @var RuleInterface $rule */
            $rule = new $ruleClass();
            if (! $rule instanceof RuleInterface) {
                $msg = "Rule {$ruleClass} does not implement RuleInterface.";
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            if ($ruleClass::getDriver() !== $activeDriver) {
                $msg = "Skipping rule {$ruleClass}: requires driver '{$ruleClass::getDriver()}', connected to '{$activeDriver}'.";
                $io->warning($msg);
                $logger($msg);
                continue;
            }

            $title = 'Running rule: ' . $ruleClass::getName() . ' [' . $ruleClass::getCategory() . ']';
            $io->section($title);
            $logger($title);

            $ruleContext = $constraintsObj instanceof RuleConstraintInterface
                ? $constraintsObj->toContext()
                : ($inlineContext !== [] ? $inlineContext : []);
            $ruleContext['dry'] = $isCapturing ? true : $isDry;

            if (! $isDry && $rule instanceof BreaksExpandContractPatternInterface) {
                $io->warning(sprintf(
                    '%s does not follow the Expand/Contract pattern — it modifies existing columns or table properties in-place and may lock tables. Ensure you have a backup before continuing.',
                    $ruleClass::getName()
                ));
            }

            $useTransaction = ! $ruleClass::isDestructive() && ! $isDry && ! $isCapturing;

            try {
                if ($useTransaction) {
                    $pdo->beginTransaction();
                }

                $logs = $rule->apply($pdo, $io, $ruleContext);
                $issueCount = count($logs);

                if ($isCapturing) {
                    foreach ($logs as $log) {
                        if ($this->isSqlStatement($log->getTo())) {
                            $result->capturedSql[] = rtrim(trim($log->getTo()), ';');
                        }
                    }
                }

                $result->reportRows[] = [
                    'name' => $ruleClass::getName(),
                    'count' => $issueCount,
                    'group' => 'standalone',
                ];

                if ($isReport) {
                    $logger("{$ruleClass}: {$issueCount} findings");
                } elseif ($issueCount === 0) {
                    $io->success('No issues found by this rule.');
                    $logger("No issues found by {$ruleClass}");
                } else {
                    foreach ($logs as $log) {
                        $msg = $log->getMessage();
                        $logger($msg);
                        $io->warning($msg);
                    }
                    $io->note("Findings: {$issueCount}");
                    $logger("Findings: {$issueCount}");
                }

                if (! $isReport) {
                    if ($useTransaction) {
                        $pdo->commit();
                        $io->success('Transaction committed.');
                        $logger("✔ {$ruleClass} committed");
                    } elseif ($isDry) {
                        $io->note('Dry run: no schema changes were executed.');
                        $logger("✔ {$ruleClass} rolled back (dry run)");
                    } else {
                        $io->success('Rule finished.');
                        $logger("✔ {$ruleClass} applied");
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
                $logger("✘ {$ruleClass} failed: " . $e->getMessage());
            }

            if (! $isReport) {
                $io->newLine();
                $logger(str_repeat('-', 80));
            }
        }
    }

    /**
     * @param mixed $key
     * @param mixed $def
     */
    private function resolveRuleClass($key, $def): ?string
    {
        if (is_string($def) && class_exists($def)) {
            return $def;
        }
        if ($def instanceof RuleConstraintInterface && is_string($key) && class_exists($key)) {
            return $key;
        }
        if (is_array($def)) {
            if (isset($def['class']) && is_string($def['class'])) {
                return $def['class'];
            }
            if (isset($def[0]) && is_string($def[0])) {
                return $def[0];
            }
            if (is_string($key) && class_exists($key)) {
                return $key;
            }
        }
        if (is_string($key) && class_exists($key)) {
            return $key;
        }
        return null;
    }

    /**
     * Extract an inline context array from an array rule definition.
     *
     * @param mixed $key
     * @param array<mixed> $def
     * @return array<mixed>
     */
    private function extractInlineContext($key, array $def): array
    {
        if (isset($def['context']) && is_array($def['context'])) {
            return $def['context'];
        }
        if (isset($def[1]) && is_array($def[1])) {
            return $def[1];
        }
        if (is_string($key) && class_exists($key)) {
            return $def;
        }
        return [];
    }

    private function isSqlStatement(string $s): bool
    {
        $upper = ltrim(strtoupper(trim($s)));
        foreach (['ALTER ', 'CREATE ', 'DROP ', 'RENAME ', 'INSERT ', 'UPDATE ', 'DELETE ', 'TRUNCATE '] as $kw) {
            if (strpos($upper, $kw) === 0) {
                return true;
            }
        }
        return false;
    }
}
