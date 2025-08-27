<?php

namespace DbFixer\Rule\Validation;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class DetectOrphanedChildRowsRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'detect_orphaned_child_rows';
    }

    public static function getCategory(): string
    {
        return 'Validation';
    }

    public static function isDestructive(): bool
    {
        return false;
    }

    /**
     * @return Log[]
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $logs = [];
        $foreignKeys = $this->getForeignKeyConstraints($pdo);

        foreach ($foreignKeys as $fk) {
            $table = $fk['table_name'];
            $column = $fk['column_name'];
            $parentTable = $fk['referenced_table_name'];
            $parentColumn = $fk['referenced_column_name'];

            $sql = <<<SQL
SELECT child.{$column} AS orphan_id
FROM {$table} AS child
LEFT JOIN {$parentTable} AS parent
ON child.{$column} = parent.{$parentColumn}
WHERE child.{$column} IS NOT NULL AND parent.{$parentColumn} IS NULL
SQL;

            $stmt = $pdo->query($sql);
            $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($orphans as $id) {
                $logs[] = new Log(
                    rule: self::getName(),
                    table: $table,
                    column: $column,
                    from: (string)$id,
                    to: 'ORPHAN (no match)'
                );
            }
        }

        return $logs;
    }

    private function getForeignKeyConstraints(PDO $pdo): array
    {
        $sql = <<<SQL
SELECT
    kcu.TABLE_NAME AS table_name,
    kcu.COLUMN_NAME AS column_name,
    kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
    kcu.REFERENCED_COLUMN_NAME AS referenced_column_name
FROM
    information_schema.KEY_COLUMN_USAGE kcu
WHERE
    kcu.REFERENCED_TABLE_NAME IS NOT NULL
    AND kcu.TABLE_SCHEMA = DATABASE()
SQL;

        return array_map(
            fn(array $row) => array_change_key_case($row, CASE_LOWER),
            $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
