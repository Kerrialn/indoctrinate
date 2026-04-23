<?php

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsureAutoIncrementPrimaryKeyRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureAutoIncrementPrimaryKeyRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'ensure_auto_increment_primary_key';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'ensures that tables have an int auto-increment primary key';
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
        $isDry = (bool) ($context['dry'] ?? false);

        $forceOnJoins = (bool) ($context['force_on_join_tables'] ?? false);
        $replaceSingleNonIntPrimary = (bool) ($context['replace_single_non_int_primary'] ?? false);

        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $output->writeln(sprintf('[%s] scanned %d tables', self::getName(), \count($tables)));

        foreach ($tables as $table) {
            $pkCols = $this->getPrimaryKeyColumns($pdo, $table);

            // Already ideal: single int AUTO_INCREMENT on `id`
            if (\count($pkCols) === 1 && strtolower($pkCols[0]) === 'id') {
                $idInfo = $this->getColumnInfo($pdo, $table, 'id');
                if ($idInfo && $this->isIntegerFamily($idInfo['DATA_TYPE']) && stripos($idInfo['EXTRA'] ?? '', 'auto_increment') !== false) {
                    continue;
                }
            }

            if (! $forceOnJoins && $this->isPureJoinTable($pdo, $table)) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(composite key)',
                    'many-to-many join table',
                    'OK to keep composite primary key on the two foreign keys; no surrogate id needed'
                );
                continue;
            }

            if ($pkCols === []) {
                $plan = $this->planForNoPrimaryKey($pdo, $table);
                $this->emitOrApply($pdo, $results, $table, $plan, $isDry);
                continue;
            }

            if (\count($pkCols) > 1) {
                $plan = $this->planForCompositePrimaryKey($table, $pkCols);
                $this->emitOrApply($pdo, $results, $table, $plan, $isDry);
                continue;
            }

            // Single-column PK
            $pkCol = $pkCols[0];
            $pkInfo = $this->getColumnInfo($pdo, $table, $pkCol);

            if ($pkInfo && $this->isIntegerFamily($pkInfo['DATA_TYPE'])) {
                if (strtolower($pkCol) === 'id' && stripos($pkInfo['EXTRA'] ?? '', 'auto_increment') !== false) {
                    continue;
                }
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $pkCol,
                    strtoupper($pkInfo['COLUMN_TYPE']) . ' PRIMARY KEY',
                    'Already a single integer primary key (leave as-is)'
                );
                continue;
            }

            if ($replaceSingleNonIntPrimary) {
                $plan = $this->planForSingleNonIntegerPrimary($table, $pkCol);
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

        $orderCols = $this->guessStableOrdering($pdo, $table);
        $orderSql = $this->orderBySql($orderCols);

        $steps = [];
        $notes = [];

        if ($idInfo === null) {
            $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        } elseif (! $this->isIntegerFamily($idInfo['DATA_TYPE'])) {
            $notes[] = "Existing `id` is " . strtoupper($idInfo['COLUMN_TYPE']) . " — will be replaced by integers";
            $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        }

        // Backfill
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);

        // Promote to PK
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));

        // Set AUTO_INCREMENT to MAX(id)+1 (handled in PHP)
        $steps[] = '__SET_AUTO_INCREMENT_FROM_MAX__';

        return [$steps, $notes, 'no primary key → add `id` auto-increment primary key'];
    }

    private function planForCompositePrimaryKey(string $table, array $pkCols): array
    {
        $pkColsSql = implode(', ', array_map(fn($c) => '`' . $this->qt($c) . '`', $pkCols));
        $uniqName = $this->uniqueName("uniq_{$table}_old_pk");
        $orderSql = $this->orderBySql($pkCols);

        $steps = [];
        $notes = ["current primary key: ($pkColsSql)"];

        $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);
        $steps[] = sprintf("ALTER TABLE `%s` ADD UNIQUE `%s` (%s)", $this->qt($table), $this->qt($uniqName), $pkColsSql);
        $steps[] = sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));
        $steps[] = '__SET_AUTO_INCREMENT_FROM_MAX__';

        return [$steps, $notes, 'composite primary key → add `id`, keep old key UNIQUE, make `id` primary'];
    }

    private function planForSingleNonIntegerPrimary(string $table, string $pkCol): array
    {
        $pkColsSql = '`' . $this->qt($pkCol) . '`';
        $uniqName = $this->uniqueName("uniq_{$table}_old_pk");
        $orderSql = $this->orderBySql([$pkCol]);

        $steps = [];
        $notes = ["current primary key: ($pkColsSql)"];

        $steps[] = sprintf("ALTER TABLE `%s` ADD COLUMN `id` INT UNSIGNED NULL", $this->qt($table));
        $steps[] = "SET @row := 0";
        $steps[] = sprintf("UPDATE `%s` SET `id` = (@row := @row + 1)%s", $this->qt($table), $orderSql);
        $steps[] = sprintf("ALTER TABLE `%s` ADD UNIQUE `%s` (%s)", $this->qt($table), $this->qt($uniqName), $pkColsSql);
        $steps[] = sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL", $this->qt($table));
        $steps[] = sprintf("ALTER TABLE `%s` ADD PRIMARY KEY (`id`)", $this->qt($table));
        $steps[] = '__SET_AUTO_INCREMENT_FROM_MAX__';

        return [$steps, $notes, 'single non-integer primary key → add `id`, keep old key UNIQUE, make `id` primary'];
    }

    private function emitOrApply(PDO $pdo, array &$results, string $table, array $plan, bool $isDry): void
    {
        [$steps, $notes, $headline] = $plan;
        $noteStr = $notes ? ' (' . implode('; ', $notes) . ')' : '';

        if ($isDry) {
            // Replace sentinel with a readable description
            $prettySteps = array_map(fn($s) => $s === '__SET_AUTO_INCREMENT_FROM_MAX__'
                ? sprintf("ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = MAX(id)+1", $this->qt($table))
                : $s, $steps);

            $results[] = new Log(
                self::getName(),
                $table,
                'id',
                'plan',
                $headline . $noteStr . ' ; ' . implode('  →  ', $prettySteps)
            );
            return;
        }

        foreach ($steps as $sql) {
            if ($sql === '__SET_AUTO_INCREMENT_FROM_MAX__') {
                // Compute next auto-increment value in PHP and inject as literal
                $stmt = $pdo->query(sprintf("SELECT MAX(`id`) FROM `%s`", $this->qt($table)));
                $max = (int) $stmt->fetchColumn();
                $next = $max > 0 ? $max + 1 : 1;

                $final = sprintf(
                    "ALTER TABLE `%s` MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = %d",
                    $this->qt($table),
                    $next
                );
                $pdo->exec($final);
                $results[] = new Log(self::getName(), $table, 'id', 'executed', $final);
                continue;
            }

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
        $stmt->execute([
            ':t' => $table,
        ]);
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
        $stmt->execute([
            ':t' => $table,
            ':c' => $column,
        ]);
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
        $stmt->execute([
            ':t' => $table,
        ]);
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
        $stmt->execute([
            ':t' => $table,
        ]);
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
        $pk = $this->getPrimaryKeyColumns($pdo, $table);
        if ($pk !== []) {
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
        $stmt->execute([
            ':t' => $table,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) {
            return [];
        }
        $firstIndex = $rows[0]['INDEX_NAME'];
        $cols = [];
        foreach ($rows as $r) {
            if ($r['INDEX_NAME'] !== $firstIndex) {
                break;
            }
            $cols[] = $r['COLUMN_NAME'];
        }
        return $cols;
    }

    private function orderBySql(array $cols): string
    {
        if ($cols === []) {
            return '';
        }
        $parts = array_map(fn($c) => '`' . $this->qt($c) . '` ASC', $cols);
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function uniqueName(string $base): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $base);
        return substr($s, 0, 55) . '_' . substr(md5($base), 0, 8);
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }

    public static function getConstraintClass(): ?string
    {
        return EnsureAutoIncrementPrimaryKeyRuleConstraints::class;
    }
}