<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\Integrity\Constraint\EnsureCharsetCollationRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureCharsetCollationRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'ensure_charset_collation';
    }

    public static function getDescription(): string
    {
        return 'ensures all tables and text columns use a consistent character set and collation';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function isDestructive(): bool
    {
        // CONVERT TO CHARACTER SET rewrites the entire table; report-only.
        return true;
    }

    public static function getConstraintClass(): string
    {
        return EnsureCharsetCollationRuleConstraints::class;
    }

    /** @param array<string, mixed> $context */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $targetCharset = (string) ($context['target_charset'] ?? 'utf8mb4');
        $targetCollation = (string) ($context['target_collation'] ?? 'utf8mb4_unicode_ci');
        $skipTables = array_map('strtolower', (array) ($context['skip_tables'] ?? []));
        $skipTableLike = (array) ($context['skip_table_like'] ?? ['%tmp%', '%temp%', '%cache%']);
        $onlyTables = array_map('strtolower', (array) ($context['only_tables'] ?? []));
        $onlyTableLike = (array) ($context['only_table_like'] ?? []);
        $checkColumns = (bool) ($context['check_columns'] ?? true);
        $debug = (bool) ($context['debug'] ?? false);

        $allow = function (string $table) use ($onlyTables, $onlyTableLike, $skipTables, $skipTableLike): bool {
            $t = strtolower($table);
            if ($skipTables !== [] && \in_array($t, $skipTables, true)) {
                return false;
            }
            foreach ($skipTableLike as $pat) {
                if ($this->likeMatch($table, $pat)) {
                    return false;
                }
            }
            $hasOnly = ($onlyTables !== [] || $onlyTableLike !== []);
            if ($hasOnly) {
                if ($onlyTables !== [] && \in_array($t, $onlyTables, true)) {
                    return true;
                }
                foreach ($onlyTableLike as $pat) {
                    if ($this->likeMatch($table, $pat)) {
                        return true;
                    }
                }
                return false;
            }
            return true;
        };

        $results = [];

        // --- Table-level charset/collation check ---
        $tables = $this->getTables($pdo);

        $output->writeln(sprintf('[%s] scanned %d tables', self::getName(), \count($tables)));

        $tableMismatches = 0;
        foreach ($tables as $t) {
            $table = $t['TABLE_NAME'];
            $tableCharset = $t['CHARACTER_SET_NAME'];
            $tableCollation = $t['TABLE_COLLATION'];

            if (! $allow($table)) {
                continue;
            }

            if ($tableCharset === $targetCharset && $tableCollation === $targetCollation) {
                continue;
            }

            $tableMismatches++;
            $from = sprintf('%s / %s', $tableCharset, $tableCollation);
            $to = sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                $table,
                $targetCharset,
                $targetCollation
            );

            $results[] = new Log(self::getName(), $table, '(table)', $from, $to);
        }

        $output->writeln(sprintf('  • tables with wrong charset/collation: %d', $tableMismatches));

        // --- Column-level charset/collation check ---
        // Catches columns with explicit CHARACTER SET overrides that differ from the target,
        // even on tables whose table-level charset is already correct.
        if ($checkColumns) {
            $columns = $this->getMismatchedTextColumns($pdo, $targetCharset, $targetCollation);

            $colMismatches = 0;
            foreach ($columns as $col) {
                $table = $col['TABLE_NAME'];

                if (! $allow($table)) {
                    continue;
                }

                $colMismatches++;
                $column = $col['COLUMN_NAME'];
                $colType = $col['COLUMN_TYPE'];
                $colCharset = $col['CHARACTER_SET_NAME'];
                $colCollation = $col['COLLATION_NAME'];

                $from = sprintf('%s CHARACTER SET %s COLLATE %s', $colType, $colCharset, $colCollation);
                $to = sprintf(
                    'ALTER TABLE `%s` MODIFY COLUMN `%s` %s CHARACTER SET %s COLLATE %s',
                    $table,
                    $column,
                    $colType,
                    $targetCharset,
                    $targetCollation
                );

                $results[] = new Log(self::getName(), $table, $column, $from, $to);
            }

            $output->writeln(sprintf('  • columns with wrong charset/collation: %d', $colMismatches));
        }

        if ($debug && $results !== []) {
            foreach (array_slice($results, 0, 5) as $log) {
                $output->writeln('  → ' . $log->getMessage());
            }
        }

        return $results;
    }

    /** @return array<int, array<string, string>> */
    private function getTables(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                t.TABLE_NAME,
                t.TABLE_COLLATION,
                ccsa.CHARACTER_SET_NAME
            FROM INFORMATION_SCHEMA.TABLES t
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY ccsa
                ON ccsa.COLLATION_NAME = t.TABLE_COLLATION
            WHERE t.TABLE_SCHEMA = DATABASE()
              AND t.TABLE_TYPE = 'BASE TABLE'
            ORDER BY t.TABLE_NAME
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, string>> */
    private function getMismatchedTextColumns(PDO $pdo, string $targetCharset, string $targetCollation): array
    {
        $stmt = $pdo->prepare("
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                COLUMN_TYPE,
                CHARACTER_SET_NAME,
                COLLATION_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND CHARACTER_SET_NAME IS NOT NULL
              AND (CHARACTER_SET_NAME != :charset OR COLLATION_NAME != :collation)
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        $stmt->execute([
            ':charset' => $targetCharset,
            ':collation' => $targetCollation,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function likeMatch(string $table, string $likePattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }
}
