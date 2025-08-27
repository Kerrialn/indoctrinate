<?php

namespace DbFixer\Rule\Integrity;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureAutoIncrementPrimaryKeyRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'ensure_auto_increment_primary_key';
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
        $isDry = (bool)($context['dry'] ?? false);

        $forceOnJoins = (bool)($context['force_on_join_tables'] ?? false);
        $replaceSingleNonIntPrimary = (bool)($context['replace_single_non_int_primary'] ?? false);

        // Collect base tables
        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $output->writeln(sprintf('[%s] scanned %d tables', self::getName(), \count($tables)));

        foreach ($tables as $table) {
            $pkCols = $this->getPrimaryKeyColumns($pdo, $table);

            // 0) Already good? Single-column primary named 'id', integer family, auto-increment → skip
            if (\count($pkCols) === 1 && strtolower($pkCols[0]) === 'id') {
                $idInfo = $this->getColumnInfo($pdo, $table, 'id');
                if ($idInfo && $this->isIntegerFamily($idInfo['DATA_TYPE']) && stripos($idInfo['EXTRA'] ?? '', 'auto_increment') !== false) {
                    continue;
                }
            }

            // Detect pure join tables and skip unless forced
            if (!$forceOnJoins && $this->isPureJoinTable($pdo, $table)) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(composite key)',
                    'many-to-many join table',
                    'OK to keep composite primary key on the two foreign keys; no surrogate id needed'
                );
                continue;
            }

            // Case A: table has no primary key at all
            if (empty($pkCols)) {
                $plan = $this->planForNoPrimaryKey($pdo, $table);
                $this->emitOrApply($pdo, $results, $table, $plan, $isDry);
                continue;
            }

            // Case B: composite primary key
            if (\count($pkCols) > 1) {
                $plan = $this->planForCompositePrimaryKey($pdo, $table, $pkCols);
                $this->emitOrApply($pdo, $results, $table, $plan, $isDry);
                continue;
            }

            // Case C: single-column primary key
            $pkCol = $pkCols[0];
            $pkInfo = $this->getColumnInfo($pdo, $table, $pkCol);

            if ($pkInfo && $this->isIntegerFamily($pkInfo['DATA_TYPE'])) {
                // Single integer primary key (but maybe not named 'id')
                if (strtolower($pkCol) === 'id' && stripos($pkInfo['EXTRA'] ?? '', 'auto_increment') !== false) {
                    // Already ideal
                    continue;
                }
                // If it is integer, we can either rename to id (risky) or leave as-is; we leave as-is.
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $pkCol,
                    strtoupper($pkInfo['COLUMN_TYPE']) . ' PRIMARY KEY',
                    'Already a single integer primary key (leave as-is)'
                );
                continue;
            }

            // Single-column primary key but not integer: propose adding an integer id only if enabled
            if ($replaceSingleNonIntPrimary) {
                $plan = $this->planForSingleNonIntegerPrimary($pdo, $table, $pkCol);
                $this->emitOrApply($pdo, $results, $table, $plan, $isDry);
            } else {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $pkCol,
                    strtoupper($pkInfo['COLUMN_TYPE']) . ' PRIMARY KEY',
                    'Single-column primary key is not integer; skipping (set replace_single_non_int_primary=true to add an integer id primary key)'
                );
            }
        }

        return $results;
    }

    private function planForNoPrimaryKey(PDO $pdo, string $table): array
    {
        $idInfo = $this->getColumnInfo($pdo, $table, 'id');

        // Choose a stable ordering if possible
        $orderCols = $this->guessStableOrdering($pdo, $table); // array of column names, may be empty
        $orderSql = $this->orderBySql($orderCols);

        $steps = [];
        $notes = [];

        if ($idInfo === null) {
            // Add nullable id first
            $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        } elseif (!$this->isIntegerFamily($idInfo['DATA_TYPE'])) {
            // If an id exists but is not integer, we will overwrite it. Warn and change type to integer.
            $notes[] = "Existing `id` is " . strtoupper($idInfo['COLUMN_TYPE']) . " — will be replaced by integers";
            $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        }

        // Backfill sequential values
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);

        // Make id not null, set as primary, and enable auto increment
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));

        // Ensure the next inserts continue the sequence
        $steps[] = sprintf("SET @mx := (SELECT MAX(`id`) FROM `%s`)", $this->qt($table));
        $steps[] = sprintf("SET @next := IFNULL(@mx, 0) + 1", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = @next", $this->qt($table));

        return [$steps, $notes, 'no primary key → add `id` auto-increment primary key'];
    }

    private function planForCompositePrimaryKey(PDO $pdo, string $table, array $pkCols): array
    {
        $pkColsSql = implode(', ', array_map(fn($c) => '`' . $this->qt($c) . '`', $pkCols));
        $uniqName = $this->uniqueName("uniq_{$table}_old_pk");

        // Choose a stable ordering (use the current primary key columns if possible)
        $orderSql = $this->orderBySql($pkCols);

        $steps = [];
        $notes = ["current primary key: ($pkColsSql)"];

        // 1) Add nullable id
        $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));

        // 2) Backfill sequential values using a stable order
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);

        // 3) Preserve the old key as UNIQUE so existing foreign keys remain valid
        $steps[] = sprintf("ALTER TABLE `%s` ADD UNIQUE `%s` (%s)", $this->qt($table), $this->qt($uniqName), $pkColsSql);

        // 4) Switch the primary key to id
        $steps[] = sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));

        // 5) Enable auto increment and set next value
        $steps[] = sprintf("SET @mx := (SELECT MAX(`id`) FROM `%s`)", $this->qt($table));
        $steps[] = sprintf("SET @next := IFNULL(@mx, 0) + 1", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = @next", $this->qt($table));

        return [$steps, $notes, 'composite primary key → add `id`, keep old key UNIQUE, make `id` primary'];
    }

    private function planForSingleNonIntegerPrimary(PDO $pdo, string $table, string $pkCol): array
    {
        $pkColsSql = '`' . $this->qt($pkCol) . '`';
        $uniqName = $this->uniqueName("uniq_{$table}_old_pk");

        // Choose a stable ordering by the old primary key
        $orderSql = $this->orderBySql([$pkCol]);

        $steps = [];
        $notes = ["current primary key: ($pkColsSql)"];

        // 1) Add nullable id
        $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));

        // 2) Backfill sequential values
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);

        // 3) Preserve the old key as UNIQUE
        $steps[] = sprintf("ALTER TABLE `%s` ADD UNIQUE `%s` (%s)", $this->qt($table), $this->qt($uniqName), $pkColsSql);

        // 4) Switch the primary key
        $steps[] = sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));

        // 5) Enable auto increment and set next value
        $steps[] = sprintf("SET @mx := (SELECT MAX(`id`) FROM `%s`)", $this->qt($table));
        $steps[] = sprintf("SET @next := IFNULL(@mx, 0) + 1", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = @next", $this->qt($table));

        return [$steps, $notes, 'single non-integer primary key → add `id`, keep old key UNIQUE, make `id` primary'];
    }

    private function emitOrApply(PDO $pdo, array &$results, string $table, array $plan, bool $isDry): void
    {
        [$steps, $notes, $headline] = $plan;
        $noteStr = $notes ? ' (' . implode('; ', $notes) . ')' : '';

        if ($isDry) {
            $results[] = new Log(
                self::getName(),
                $table,
                'id',
                'plan',
                $headline . $noteStr . ' ; ' . implode('  →  ', $steps)
            );
            return;
        }

        // Apply
        foreach ($steps as $sql) {
            $pdo->exec($sql);
            $results[] = new Log(self::getName(), $table, 'id', 'executed', $sql);
        }
    }

    // ---------- Helpers ----------

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

    private function getColumnInfo(PDO $pdo, string $table, string $column): ?array
    {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function isIntegerFamily(string $dataType): bool
    {
        $d = strtolower($dataType);
        return in_array($d, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true);
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
        $pkCols = $this->getPrimaryKeyColumns($pdo, $table);
        if (\count($pkCols) !== 2) {
            return false;
        }
        $fkCols = $this->getForeignKeyColumns($pdo, $table);
        if (\count(array_intersect($pkCols, $fkCols)) !== 2) {
            return false;
        }
        $allCols = $this->getAllColumns($pdo, $table);
        $nonKeyCols = array_diff($allCols, $pkCols);
        return \count($nonKeyCols) === 0;
    }

    private function guessStableOrdering(PDO $pdo, string $table): array
    {
        // Prefer current primary key columns, else first UNIQUE index columns, else empty (no ORDER BY)
        $pk = $this->getPrimaryKeyColumns($pdo, $table);
        if (!empty($pk)) {
            return $pk;
        }

        $stmt = $pdo->prepare("
            SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND NON_UNIQUE = 0
              AND INDEX_NAME <> 'PRIMARY'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        // pick the first unique index by name
        $firstIndex = $rows[0]['INDEX_NAME'];
        $cols = [];
        foreach ($rows as $r) {
            if ($r['INDEX_NAME'] !== $firstIndex) break;
            $cols[] = $r['COLUMN_NAME'];
        }
        return $cols;
    }

    private function orderBySql(array $cols): string
    {
        if (empty($cols)) {
            return '';
        }
        $parts = array_map(fn($c) => '`' . $this->qt($c) . '` ASC', $cols);
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function uniqueName(string $base): string
    {
        // Keep it short to avoid hitting 64-char identifier limits
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $base);
        return substr($s, 0, 55) . '_' . substr(md5($base), 0, 8);
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}
