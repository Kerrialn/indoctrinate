<?php

namespace Indoctrinate\Rule\Normalization;

use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class NormalizeTinyint4ColumnsRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'normalize_tinyint4_columns';
    }

    public static function getDescription(): string
    {
        return 'ensures that TINYINT(4) columns are normalized';
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
        $columns = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE = 'tinyint'
              AND COLUMN_TYPE = 'tinyint(4)'
        ")->fetchAll(PDO::FETCH_ASSOC);

        var_dump($columns);
        exit();
    }

    public static function getConstraintClass(): ?string
    {
        return null;
    }
}
