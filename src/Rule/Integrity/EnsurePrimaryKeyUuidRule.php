<?php

namespace DbFixer\Rule\Integrity;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsurePrimaryKeyUuidRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'ensure_primary_key_uuid';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function isDestructive(): bool
    {
        return true;
    }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $results = [];

        $preferUuid = (bool)($context['prefer_uuid'] ?? true);
        $forceSurrogateOnJoins = (bool)($context['force_surrogate_on_joins'] ?? false);

        // All base tables
        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $output->writeln(sprintf('[%s] scanned %d tables', self::getName(), \count($tables)));

        // Tables without a primary key
        $noPrimaryKeyTables = $pdo->query("
            SELECT t.TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES t
            LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS c
              ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
             AND c.TABLE_NAME   = t.TABLE_NAME
             AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
            WHERE t.TABLE_SCHEMA = DATABASE()
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND c.CONSTRAINT_NAME IS NULL
        ")->fetchAll(PDO::FETCH_COLUMN);

        // Tables with composite primary keys
        $compositePrimaryKeyTables = $pdo->query("
            SELECT k.TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS c
            JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
              ON k.TABLE_SCHEMA = c.TABLE_SCHEMA
             AND k.TABLE_NAME   = c.TABLE_NAME
             AND k.CONSTRAINT_NAME = c.CONSTRAINT_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
            GROUP BY k.TABLE_NAME
            HAVING COUNT(*) > 1
        ")->fetchAll(PDO::FETCH_COLUMN);

        // Map: table => list of current primary key columns
        $primaryKeyColumnsByTable = [];
        $pkStmt = $pdo->query("
            SELECT k.TABLE_NAME, k.COLUMN_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS c
            JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
              ON k.TABLE_SCHEMA = c.TABLE_SCHEMA
             AND k.TABLE_NAME   = c.TABLE_NAME
             AND k.CONSTRAINT_NAME = c.CONSTRAINT_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION
        ");
        foreach ($pkStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $primaryKeyColumnsByTable[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
        }

        // Map: table => number of child foreign keys referencing it
        $childForeignKeyCountByTable = [];
        $childStmt = $pdo->query("
            SELECT
                k.REFERENCED_TABLE_NAME AS parent_table,
                COUNT(DISTINCT CONCAT(k.TABLE_NAME, ':', k.CONSTRAINT_NAME)) AS child_fk_count
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            GROUP BY k.REFERENCED_TABLE_NAME
        ");
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $childForeignKeyCountByTable[$r['parent_table']] = (int)$r['child_fk_count'];
        }

        $output->writeln(sprintf('  • tables without primary key: %d', \count($noPrimaryKeyTables)));
        $output->writeln(sprintf('  • tables with composite primary key: %d', \count($compositePrimaryKeyTables)));

        // Suggestions for tables without a primary key
        foreach ($noPrimaryKeyTables as $table) {
            $affects = $childForeignKeyCountByTable[$table] ?? 0;
            $idInfo = $this->getIdColumnInfo($pdo, $table);

            if ($idInfo === null) {
                $target = $preferUuid
                    ? "ADD COLUMN `id` CHAR(36) NOT NULL; populate with UUIDs; ADD PRIMARY KEY (`id`); affects {$affects} child tables"
                    : "ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT; ADD PRIMARY KEY (`id`); affects {$affects} child tables";

                $results[] = new Log(self::getName(), $table, 'id', 'missing', $target);
                continue;
            }

            $current = strtoupper($idInfo['COLUMN_TYPE']) . ($idInfo['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL');

            if ($preferUuid) {
                if (!$this->isChar36($idInfo['COLUMN_TYPE'])) {
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        'id',
                        $current,
                        "CHANGE `id` to CHAR(36) NOT NULL (UUID storage); ensure uniqueness; ADD PRIMARY KEY (`id`); affects {$affects} child tables"
                    );
                } elseif ($idInfo['IS_NULLABLE'] === 'YES') {
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        'id',
                        $current,
                        "SET `id` NOT NULL; ADD PRIMARY KEY (`id`); affects {$affects} child tables"
                    );
                } else {
                    $results[] = new Log(self::getName(), $table, 'id', 'no primary key', "ADD PRIMARY KEY (`id`); affects {$affects} child tables");
                }
            } else {
                if (preg_match('/^(?:int|bigint)\b/i', $idInfo['DATA_TYPE']) && $idInfo['IS_NULLABLE'] === 'NO') {
                    $results[] = new Log(self::getName(), $table, 'id', $current, "ADD PRIMARY KEY (`id`); affects {$affects} child tables");
                } else {
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        'id',
                        $current,
                        "CHANGE `id` to BIGINT UNSIGNED NOT NULL AUTO_INCREMENT; ADD PRIMARY KEY (`id`); affects {$affects} child tables"
                    );
                }
            }
        }

        // Suggestions for tables with composite primary keys
        foreach ($compositePrimaryKeyTables as $table) {
            // Skip pure join tables unless explicitly forced
            if (!$forceSurrogateOnJoins && $this->isPureJoinTable($pdo, $table)) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(composite key)',
                    'many-to-many join table',
                    'OK to keep composite PRIMARY KEY on the two foreign keys; no surrogate id needed'
                );
                continue;
            }

            $affects = $childForeignKeyCountByTable[$table] ?? 0;
            $prevCols = $primaryKeyColumnsByTable[$table] ?? [];
            $prevColsSql = implode(', ', array_map(fn($c) => "`$c`", $prevCols)) ?: '(unknown)';

            $target = $preferUuid
                ? "ADD COLUMN `id` CHAR(36) NOT NULL; populate with UUIDs; DROP current PRIMARY KEY; ADD PRIMARY KEY (`id`); ADD UNIQUE ({$prevColsSql}); affects {$affects} child tables"
                : "ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT; DROP current PRIMARY KEY; ADD PRIMARY KEY (`id`); ADD UNIQUE ({$prevColsSql}); affects {$affects} child tables";

            $results[] = new Log(self::getName(), $table, 'id', 'composite primary key present', $target);
        }

        // Optional preview
        if (!empty($results) && ($context['debug'] ?? false)) {
            foreach (array_slice($results, 0, 5) as $log) {
                $output->writeln(sprintf(
                    '  → %s.%s: %s => %s',
                    $log->getTable(),
                    $log->getColumn(),
                    $log->getCurrent(),
                    $log->getTarget()
                ));
            }
        }

        return $results;
    }

    private function getIdColumnInfo(PDO $pdo, string $table): ?array
    {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = 'id'
            LIMIT 1
        ");
        $stmt->execute([':t' => $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getPrimaryKeyColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT k.COLUMN_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS c
            JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
              ON k.TABLE_SCHEMA=c.TABLE_SCHEMA
             AND k.TABLE_NAME=c.TABLE_NAME
             AND k.CONSTRAINT_NAME=c.CONSTRAINT_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND c.TABLE_NAME = :t
              AND c.CONSTRAINT_TYPE='PRIMARY KEY'
            ORDER BY k.ORDINAL_POSITION
        ");
        $stmt->execute([':t' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }

    private function getForeignKeyColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT k.COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.TABLE_NAME = :t
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([':t' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }

    private function getAllColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
        ");
        $stmt->execute([':t' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    }

    private function isPureJoinTable(PDO $pdo, string $table): bool
    {
        // Must have exactly two primary key columns
        $pkCols = $this->getPrimaryKeyColumns($pdo, $table);
        if (\count($pkCols) !== 2) {
            return false;
        }

        // Both primary key columns must be foreign keys
        $fkCols = $this->getForeignKeyColumns($pdo, $table);
        if (\count(array_intersect($pkCols, $fkCols)) !== 2) {
            return false;
        }

        // No extra columns beyond those two
        $allCols = $this->getAllColumns($pdo, $table);
        $nonKeyCols = array_diff($allCols, $pkCols);
        return \count($nonKeyCols) === 0;
    }

    private function isChar36(string $columnType): bool
    {
        return (bool)preg_match('/^char\s*\(\s*36\s*\)/i', trim($columnType));
    }
}
