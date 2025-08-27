<?php

namespace DbFixer\Rule\Integrity;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class MissingForeignKeyRowsRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'fix_missing_foreign_key_rows';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
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
        $dryRun = $context['dry'] ?? true;
        $logs = [];

        $foreignKeys = $this->getForeignKeyConstraints($pdo);

        foreach ($foreignKeys as $fk) {
            $missingIds = $this->findMissingParentRows($pdo, $fk);

            foreach ($missingIds as $missingId) {
                $logs[] = new Log(
                    rule: self::getName(),
                    table: $fk['referenced_table_name'],
                    column: $fk['referenced_column_name'],
                    from: 'MISSING ID',
                    to: (string) $missingId
                );

                if (!$dryRun) {
                    $this->insertStubRow($pdo, $fk['referenced_table_name'], $fk['referenced_column_name'], $missingId);
                }
            }
        }

        return $logs;
    }

    private function getForeignKeyConstraints(PDO $pdo): array
    {
        $sql = <<<SQL
SELECT
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    kcu.CONSTRAINT_NAME
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

    private function findMissingParentRows(PDO $pdo, array $fk): array
    {
        $sql = <<<SQL
SELECT DISTINCT child.{$fk['column_name']} AS missing_id
FROM {$fk['table_name']} AS child
LEFT JOIN {$fk['referenced_table_name']} AS parent
    ON child.{$fk['column_name']} = parent.{$fk['referenced_column_name']}
WHERE
    child.{$fk['column_name']} IS NOT NULL
    AND parent.{$fk['referenced_column_name']} IS NULL
SQL;

        $stmt = $pdo->query($sql);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'missing_id');
    }

    private function insertStubRow(PDO $pdo, string $table, string $pkColumn, $id): void
    {
        $sql = "INSERT IGNORE INTO {$table} (`{$pkColumn}`) VALUES (:id)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
    }
}
