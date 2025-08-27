<?php

namespace DbFixer\Rule\Normalization;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class NormalizeIntColumnsRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'normalize_int_columns';
    }

    public static function getCategory(): string
    {
        return 'Normalization';
    }

    public static function isDestructive(): bool
    {
        // Changes would be destructive if applied (ALTER TABLE),
        // but this rule only emits Logs (no DDL here).
        return true;
    }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $results = [];

        // 1) Scan all INT columns in the current schema
        $stmt = $pdo->query("
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                COLUMN_TYPE,   -- e.g. 'int(11) unsigned zerofill'
                DATA_TYPE,     -- 'int'
                IS_NULLABLE,
                COLUMN_DEFAULT,
                COLUMN_KEY,
                EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE = 'int'
        ");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output->writeln(sprintf('[%s] scanned %d INT columns', self::getName(), \count($columns)));

        foreach ($columns as $col) {
            $table = $col['TABLE_NAME'];
            $name  = $col['COLUMN_NAME'];
            $ctype = strtolower(trim($col['COLUMN_TYPE'])); // full type string

            $isUnsigned   = $this->hasUnsigned($ctype);
            $hasZerofill  = $this->hasZerofill($ctype);
            $width        = $this->getDisplayWidth($ctype); // int or null

            // A. INT(1) looks like boolean → suggest TINYINT(1)
            if ($width === 1) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $name,
                    $col['COLUMN_TYPE'],
                    'TINYINT(1)' // Doctrine maps boolean to tinyint(1)
                );
                // continue scanning other issues; don't `continue` so it can also drop zerofill/width if relevant
            }

            // B. ZEROFILL is deprecated / not modeled by Doctrine → remove it
            if ($hasZerofill) {
                $normalized = $this->normalizedInt($isUnsigned, /*stripWidth*/ true);
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $name,
                    $col['COLUMN_TYPE'],
                    $normalized
                );
            }

            // C. Display width (INT(11)) is deprecated in MySQL 8 → drop width
            if ($width !== null) {
                // If it wasn't already suggested above, still propose a widthless type
                $normalized = $this->normalizedInt($isUnsigned, /*stripWidth*/ true);
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $name,
                    $col['COLUMN_TYPE'],
                    $normalized
                );
            }

            // D. If none of the above triggered but type has extra tokens (safety net), flag it
            if (!$hasZerofill && $width === null && !$this->isCleanInt($ctype)) {
                $normalized = $this->normalizedInt($isUnsigned, true);
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $name,
                    $col['COLUMN_TYPE'],
                    $normalized
                );
            }
        }

        // 2) Check FK signedness mismatches (child vs parent)
        // Only looks at INT types on both sides in the current schema.
        $fkStmt = $pdo->query("
            SELECT
                kcu.TABLE_NAME            AS child_table,
                kcu.COLUMN_NAME           AS child_column,
                child_cols.COLUMN_TYPE    AS child_column_type,
                kcu.REFERENCED_TABLE_NAME AS parent_table,
                kcu.REFERENCED_COLUMN_NAME AS parent_column,
                parent_cols.COLUMN_TYPE   AS parent_column_type
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.COLUMNS child_cols
                ON child_cols.TABLE_SCHEMA = kcu.TABLE_SCHEMA
               AND child_cols.TABLE_NAME   = kcu.TABLE_NAME
               AND child_cols.COLUMN_NAME  = kcu.COLUMN_NAME
            JOIN INFORMATION_SCHEMA.COLUMNS parent_cols
                ON parent_cols.TABLE_SCHEMA = kcu.TABLE_SCHEMA
               AND parent_cols.TABLE_NAME   = kcu.REFERENCED_TABLE_NAME
               AND parent_cols.COLUMN_NAME  = kcu.REFERENCED_COLUMN_NAME
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
              AND child_cols.DATA_TYPE = 'int'
              AND parent_cols.DATA_TYPE = 'int'
        ");
        $fkRows = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fkRows as $row) {
            $childUnsigned  = $this->hasUnsigned($row['child_column_type']);
            $parentUnsigned = $this->hasUnsigned($row['parent_column_type']);

            if ($childUnsigned !== $parentUnsigned) {
                // Suggest making child match parent signedness (safer than altering PKs)
                $suggest = $this->normalizedInt($parentUnsigned, true);
                $results[] = new Log(
                    self::getName(),
                    $row['child_table'],
                    $row['child_column'],
                    $row['child_column_type'],
                    $suggest
                );
            }
        }

        // De-duplicate logs that may have been added twice for the same table/column with same target
        $results = $this->uniqueLogs($results);

        // Optional: show the first few offenders when debug=true
        if (!empty($results) && ($context['debug'] ?? false)) {
            foreach (array_slice($results, 0, 5) as $log) {
                // Assuming Log has public getters; adjust if different
                $output->writeln(sprintf(
                    '  • %s.%s (%s) -> %s',
                    $log->getTable(),
                    $log->getColumn(),
                    $log->getCurrent(),
                    $log->getTarget()
                ));
            }
        }

        return $results;
    }

    private function hasUnsigned(string $columnType): bool
    {
        return str_contains($columnType, 'unsigned');
    }

    private function hasZerofill(string $columnType): bool
    {
        return str_contains($columnType, 'zerofill');
    }

    private function getDisplayWidth(string $columnType): ?int
    {
        // matches 'int(11)', 'int(1) unsigned', etc.
        if (preg_match('/^int\((\d+)\)/i', $columnType, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function isCleanInt(string $columnType): bool
    {
        // Accept exactly: 'int' or 'int unsigned' (case-insensitive, optional extra whitespace)
        return (bool) preg_match('/^int( unsigned)?$/i', trim($columnType));
    }

    private function normalizedInt(bool $unsigned, bool $stripWidth = true): string
    {
        // We always strip width for MySQL 8 compatibility.
        return $unsigned ? 'INT UNSIGNED' : 'INT';
    }

    /**
     * @param Log[] $logs
     * @return Log[]
     */
    private function uniqueLogs(array $logs): array
    {
        $seen = [];
        $out  = [];
        foreach ($logs as $log) {
            $key = $log->getRule() . '|' . $log->getTable() . '|' . $log->getColumn() . '|' . $log->getCurrent() . '|' . $log->getTarget();
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $log;
            }
        }
        return $out;
    }
}
