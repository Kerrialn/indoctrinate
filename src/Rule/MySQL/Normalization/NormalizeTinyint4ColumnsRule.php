<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Normalization;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class NormalizeTinyint4ColumnsRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'normalize_tinyint4_columns';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'flags TINYINT columns with a display width (e.g. tinyint(4)) — deprecated in MySQL 8; tinyint(1) is skipped as Doctrine uses it for boolean mapping';
    }

    public static function getCategory(): string
    {
        return 'Normalization';
    }

    public static function isDestructive(): bool
    {
        return false;
    }

    public static function getConstraintClass(): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $context */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $stmt = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE = 'tinyint'
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $output->writeln(sprintf('[%s] scanned %d TINYINT columns', self::getName(), count($columns)));

        $logs = [];

        foreach ($columns as $col) {
            $type = strtolower(trim($col['COLUMN_TYPE']));

            // tinyint(1) and tinyint(1) unsigned are intentional — Doctrine boolean mapping
            if ($type === 'tinyint(1)' || $type === 'tinyint(1) unsigned') {
                continue;
            }

            // Already clean — no display width
            if ($type === 'tinyint' || $type === 'tinyint unsigned') {
                continue;
            }

            // Has a display width that should be removed
            if (!preg_match('/^tinyint\(\d+\)( unsigned)?$/', $type)) {
                continue;
            }

            $isUnsigned = str_contains($type, 'unsigned');
            $normalized = $isUnsigned ? 'TINYINT UNSIGNED' : 'TINYINT';

            $logs[] = new Log(
                self::getName(),
                $col['TABLE_NAME'],
                $col['COLUMN_NAME'],
                $col['COLUMN_TYPE'],
                sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $col['TABLE_NAME'], $col['COLUMN_NAME'], $normalized)
            );
        }

        $output->writeln(sprintf('  • columns with deprecated display width: %d', count($logs)));

        return $logs;
    }
}
