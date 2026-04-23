<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsureIndexOnForeignKeyRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureIndexOnForeignKeyRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'ensure_index_on_foreign_key';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'ensures every foreign key column is covered by an index to prevent full-table scans on JOINs';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function isDestructive(): bool
    {
        // Suggested fix is ALTER TABLE ... ADD INDEX — can be slow on large tables.
        // This rule is report-only; no DDL is executed.
        return true;
    }

    public static function getConstraintClass(): string
    {
        return EnsureIndexOnForeignKeyRuleConstraints::class;
    }

    /** @param array<string, mixed> $context */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $skipTables = array_map('strtolower', (array) ($context['skip_tables'] ?? []));
        $skipTableLike = (array) ($context['skip_table_like'] ?? ['%tmp%', '%temp%', '%cache%']);
        $onlyTables = array_map('strtolower', (array) ($context['only_tables'] ?? []));
        $onlyTableLike = (array) ($context['only_table_like'] ?? []);
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

        $unindexed = $this->findUnindexedForeignKeys($pdo);

        $output->writeln(sprintf(
            '[%s] found %d FK column(s) with no covering index',
            self::getName(),
            \count($unindexed)
        ));

        $results = [];
        foreach ($unindexed as $row) {
            $table = $row['TABLE_NAME'];
            $column = $row['COLUMN_NAME'];

            if (! $allow($table)) {
                continue;
            }

            $indexName = sprintf('idx_%s_%s', $table, $column);
            // Truncate to MySQL's 64-char identifier limit
            if (\strlen($indexName) > 64) {
                $indexName = substr($indexName, 0, 55) . '_' . substr(md5($indexName), 0, 8);
            }

            $from = sprintf(
                'FK(%s) → %s.%s — no index',
                $row['CONSTRAINT_NAME'],
                $row['REFERENCED_TABLE_NAME'],
                $row['REFERENCED_COLUMN_NAME']
            );
            $to = sprintf('ALTER TABLE `%s` ADD INDEX `%s` (`%s`)', $table, $indexName, $column);

            $results[] = new Log(self::getName(), $table, $column, $from, $to);
        }

        if ($debug && $results !== []) {
            foreach (array_slice($results, 0, 5) as $log) {
                $output->writeln('  → ' . $log->getMessage());
            }
        }

        return $results;
    }

    /** @return array<int, array<string, string>> */
    private function findUnindexedForeignKeys(PDO $pdo): array
    {
        // A FK column is "covered" if it is the leading column (SEQ_IN_INDEX = 1)
        // in any index — including PRIMARY. MySQL requires this for InnoDB FKs,
        // but the index can be dropped manually or may be absent in MariaDB/migrated schemas.
        $stmt = $pdo->query("
            SELECT
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.CONSTRAINT_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM INFORMATION_SCHEMA.STATISTICS s
                  WHERE s.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    AND s.TABLE_NAME   = kcu.TABLE_NAME
                    AND s.COLUMN_NAME  = kcu.COLUMN_NAME
                    AND s.SEQ_IN_INDEX = 1
              )
            ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function likeMatch(string $table, string $likePattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }
}
