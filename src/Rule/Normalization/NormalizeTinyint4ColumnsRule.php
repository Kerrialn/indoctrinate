<?php

namespace DbFixer\Rule\Normalization;

use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class NormalizeTinyint4ColumnsRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'normalize_tinyint4_columns';
    }

    public static function getCategory(): string
    {
        return 'Normalization';
    }

    public static function isDestructive(): bool
    {
        return true;
    }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $results = [];

        $columns = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE = 'tinyint'
              AND COLUMN_TYPE = 'tinyint(4)'
        ")->fetchAll(PDO::FETCH_ASSOC);

        var_dump($columns);
        exit();

        foreach ($columns as $column) {
            $results[] = [
                'table' => $column['TABLE_NAME'],
                'column' => $column['COLUMN_NAME'],
                'from' => $column['COLUMN_TYPE'],
                'to' => 'tinyint',
            ];
        }

        return $results;
    }
}
