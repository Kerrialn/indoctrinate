<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\DetectVarcharIndexPrefixRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class DetectVarcharIndexPrefixRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'detect_varchar_index_prefix';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'detects VARCHAR/CHAR columns in indexes whose byte length exceeds the index prefix limit — common cause of failures after converting to utf8mb4';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function isDestructive(): bool
    {
        return false;
    }

    public static function getConstraintClass(): string
    {
        return DetectVarcharIndexPrefixRuleConstraints::class;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $targetCharset = (string) ($context['target_charset'] ?? 'utf8mb4');
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

        $bytesPerChar = $this->bytesPerChar($targetCharset);
        [$largePrefixEnabled] = $this->detectPrefixCapabilities($pdo);
        $candidates = $this->getIndexedStringColumns($pdo, $targetCharset);

        $output->writeln(sprintf('[%s] scanned %d indexed %s column(s)', self::getName(), count($candidates), $targetCharset));

        $results = [];
        $seen = [];

        foreach ($candidates as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            $key = $table . "\0" . $column;

            if (isset($seen[$key]) || ! $allow($table)) {
                continue;
            }

            $maxChars = (int) $row['CHARACTER_MAXIMUM_LENGTH'];
            $rowFormat = strtoupper((string) ($row['ROW_FORMAT'] ?? 'COMPACT'));
            $isLargeFormat = \in_array($rowFormat, ['DYNAMIC', 'COMPRESSED'], true);
            $limit = ($largePrefixEnabled && $isLargeFormat) ? 3072 : 767;
            $byteLength = $maxChars * $bytesPerChar;

            if ($byteLength <= $limit) {
                continue;
            }

            $seen[$key] = true;

            $safeMaxChars = (int) floor($limit / $bytesPerChar);
            $dataType = strtoupper((string) $row['DATA_TYPE']);
            $nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? 'YES')) === 'YES';
            $default = $row['COLUMN_DEFAULT'];
            $collation = (string) ($row['COLLATION_NAME'] ?? '');
            $indexName = (string) ($row['INDEX_NAME'] ?? '');
            $isUnique = ((string) ($row['NON_UNIQUE'] ?? '1')) === '0';

            $nullSql = $nullable ? 'NULL' : 'NOT NULL';
            $defaultSql = $default !== null ? " DEFAULT '" . addslashes((string) $default) . "'" : '';
            $collationSql = $collation !== '' ? " COLLATE {$collation}" : '';

            $from = sprintf(
                '%s(%d) CHARACTER SET %s in %sindex `%s` (%d bytes > %d-byte prefix limit)',
                $dataType,
                $maxChars,
                $targetCharset,
                $isUnique ? 'UNIQUE ' : '',
                $indexName,
                $byteLength,
                $limit
            );

            $to = sprintf(
                'ALTER TABLE `%s` MODIFY COLUMN `%s` %s(%d) CHARACTER SET %s%s %s%s',
                $table,
                $column,
                $dataType,
                $safeMaxChars,
                $targetCharset,
                $collationSql,
                $nullSql,
                $defaultSql
            );

            $results[] = new Log(self::getName(), $table, $column, $from, $to);

            if ($debug) {
                $output->writeln("  → [{$table}.{$column}] {$from}");
            }
        }

        $output->writeln(sprintf('  • columns exceeding prefix limit: %d', count($results)));

        return $results;
    }

    /**
     * Returns [largePrefixEnabled, mysqlMajorVersion].
     *
     * @return array{bool, int}
     */
    private function detectPrefixCapabilities(PDO $pdo): array
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $major = (int) explode('.', $version)[0];

        // MySQL 8.0+ always has large prefix enabled for DYNAMIC tables.
        if ($major >= 8) {
            return [true, $major];
        }

        // MySQL 5.x: check innodb_large_prefix variable.
        try {
            $row = $pdo->query("SHOW VARIABLES LIKE 'innodb_large_prefix'")->fetch(PDO::FETCH_ASSOC);
            $enabled = $row && strtoupper((string) ($row['Value'] ?? '')) === 'ON';
        } catch (\Throwable $e) {
            $enabled = false;
        }

        return [$enabled, $major];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getIndexedStringColumns(PDO $pdo, string $targetCharset): array
    {
        $stmt = $pdo->prepare('
            SELECT
                s.TABLE_NAME,
                s.INDEX_NAME,
                s.NON_UNIQUE,
                s.COLUMN_NAME,
                c.DATA_TYPE,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.CHARACTER_SET_NAME,
                c.COLLATION_NAME,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                t.ROW_FORMAT
            FROM INFORMATION_SCHEMA.STATISTICS s
            JOIN INFORMATION_SCHEMA.COLUMNS c
                ON  c.TABLE_SCHEMA = s.TABLE_SCHEMA
                AND c.TABLE_NAME   = s.TABLE_NAME
                AND c.COLUMN_NAME  = s.COLUMN_NAME
            JOIN INFORMATION_SCHEMA.TABLES t
                ON  t.TABLE_SCHEMA = s.TABLE_SCHEMA
                AND t.TABLE_NAME   = s.TABLE_NAME
            WHERE s.TABLE_SCHEMA            = DATABASE()
              AND c.DATA_TYPE               IN (\'varchar\', \'char\')
              AND c.CHARACTER_MAXIMUM_LENGTH IS NOT NULL
              AND c.CHARACTER_SET_NAME       = :charset
              AND s.SUB_PART                 IS NULL
              AND t.TABLE_TYPE              = \'BASE TABLE\'
            ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.COLUMN_NAME
        ');
        $stmt->execute([
            ':charset' => $targetCharset,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function bytesPerChar(string $charset): int
    {
        switch (true) {
            case strncmp($charset, 'utf8mb4', strlen('utf8mb4')) === 0:
                return 4;
            case strncmp($charset, 'utf8', strlen('utf8')) === 0:
                return 3;
            case strncmp($charset, 'ucs2', strlen('ucs2')) === 0:
            case strncmp($charset, 'utf16', strlen('utf16')) === 0:
            case strncmp($charset, 'utf32', strlen('utf32')) === 0:
                return 4;
            default:
                return 1;
        }
    }

    private function likeMatch(string $table, string $pattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }
}
