<?php

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureUnifiedPrimaryKeyNameRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'ensure_unified_primary_key_name';
    }

    public static function getConstraintClass(): string
    {
        return EnsureUnifiedPrimaryKeyNameRuleConstraints::class;
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'renames CHAR(36) primary keys named `uuid` to `id`, preserving child foreign keys';
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

        $onlyTables = array_map('strtolower', (array) ($context['only_tables'] ?? []));
        $onlyLike = (array) ($context['only_table_like'] ?? []);
        $skipTables = array_map('strtolower', (array) ($context['skip_tables'] ?? []));
        $skipLike = (array) ($context['skip_table_like'] ?? ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%']);
        $debug = (bool) ($context['debug'] ?? false);
        $dry = (bool) ($context['dry'] ?? false);

        // Phase flags — null means "not explicitly set" (triggers auto-detect mode).
        $runExpand = isset($context['expand']) ? (bool) $context['expand'] : null;
        $runContract = isset($context['contract']) ? (bool) $context['contract'] : null;
        $runRemove = isset($context['remove']) ? (bool) $context['remove'] : null;
        $autoDetect = ($runExpand === null && $runContract === null && $runRemove === null);

        $allow = function (string $table) use ($onlyTables, $onlyLike, $skipTables, $skipLike): bool {
            $t = strtolower($table);
            if ($skipTables && in_array($t, $skipTables, true)) {
                return false;
            }
            foreach ($skipLike as $pat) {
                if ($this->likeMatch($table, $pat)) {
                    return false;
                }
            }
            $hasOnly = ($onlyTables !== [] || $onlyLike !== []);
            if ($hasOnly) {
                if ($onlyTables && in_array($t, $onlyTables, true)) {
                    return true;
                }
                foreach ($onlyLike as $pat) {
                    if ($this->likeMatch($table, $pat)) {
                        return true;
                    }
                }
                return false;
            }
            return true;
        };

        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $tables = array_values(array_filter($tables, $allow));
        $output->writeln(sprintf('[%s] scanning %d table(s)', self::getName(), \count($tables)));

        foreach ($tables as $table) {
            $pkCols = $this->getPrimaryKeyColumns($pdo, $table);
            $uuidIsPk = \count($pkCols) === 1 && $pkCols[0] === 'uuid';
            $idIsPk = \count($pkCols) === 1 && $pkCols[0] === 'id';

            if (! $uuidIsPk && ! $idIsPk) {
                if ($debug) {
                    $output->writeln("[$table] skip: PK is not a single `uuid` or `id` column");
                }
                continue;
            }

            if ($uuidIsPk) {
                $hasId = $this->columnExists($pdo, $table, 'id');

                // --- Expand phase ---
                $shouldExpand = $autoDetect ? ! $hasId : (bool) $runExpand;
                if ($shouldExpand && ! $hasId) {
                    $uuidInfo = $this->getColumnInfo($pdo, $table, 'uuid');
                    if (! $uuidInfo || ! $this->isChar36((string) $uuidInfo['COLUMN_TYPE'])) {
                        if ($debug) {
                            $output->writeln("[$table] skip expand: `uuid` is not CHAR(36)");
                        }
                        continue;
                    }

                    if ($dry) {
                        $output->writeln("[$table] DRY expand: WOULD ADD `id` CHAR(36) NULL and backfill from `uuid`");
                    } else {
                        $pdo->exec(sprintf("ALTER TABLE `%s` ADD COLUMN `id` CHAR(36) NULL", $this->qt($table)));
                        $pdo->exec(sprintf("UPDATE `%s` SET `id` = `uuid`", $this->qt($table)));
                        $output->writeln("[$table] expand: `id` added and backfilled — deploy your app to write to `id` before contracting");
                    }

                    $results[] = new Log(self::getName(), $table, 'uuid→id', 'expand', $dry ? 'DRY: would add and backfill `id`' : 'added `id`, backfilled from `uuid`');
                    $hasId = ! $dry;
                }

                // --- Contract phase ---
                $shouldContract = $autoDetect ? $hasId : (bool) $runContract;
                if ($shouldContract && $hasId) {
                    $idInfo = $this->getColumnInfo($pdo, $table, 'id');
                    if (! $idInfo || ! $this->isChar36((string) $idInfo['COLUMN_TYPE'])) {
                        if ($debug) {
                            $output->writeln("[$table] skip contract: `id` column is not CHAR(36) — may not have been added by expand");
                        }
                        continue;
                    }

                    $children = $this->getChildFkMeta($pdo, $table, 'uuid');

                    foreach ($children as $fk) {
                        $childTable = (string) $fk['TABLE_NAME'];
                        $fkName = (string) $fk['CONSTRAINT_NAME'];
                        if ($dry) {
                            $output->writeln("[$childTable] DRY contract: WOULD DROP FOREIGN KEY `{$fkName}`");
                        } else {
                            $pdo->exec(sprintf("ALTER TABLE `%s` DROP FOREIGN KEY `%s`", $this->qt($childTable), $this->qt($fkName)));
                        }
                    }

                    if ($dry) {
                        $output->writeln("[$table] DRY contract: WOULD DROP PRIMARY KEY, MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY(`id`)");
                    } else {
                        $pdo->exec(sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table)));
                        $pdo->exec(sprintf("ALTER TABLE `%s` MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY (`id`)", $this->qt($table)));
                    }

                    foreach ($children as $fk) {
                        $childTable = (string) $fk['TABLE_NAME'];
                        $childCol = (string) $fk['COLUMN_NAME'];
                        $origName = (string) $fk['CONSTRAINT_NAME'];
                        $onDelete = strtoupper((string) ($fk['DELETE_RULE'] ?? 'RESTRICT'));
                        $onUpdate = strtoupper((string) ($fk['UPDATE_RULE'] ?? 'RESTRICT'));

                        if (! $dry && ! $this->hasIndexOnColumns($pdo, $childTable, [$childCol], false)) {
                            $idxName = $this->makeConstraintName($childTable, $childCol, 'idx');
                            $pdo->exec(sprintf(
                                "ALTER TABLE `%s` ADD INDEX `%s` (`%s`)",
                                $this->qt($childTable),
                                $this->qt($idxName),
                                $this->qt($childCol)
                            ));
                        }

                        $fkSql = sprintf(
                            "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`)",
                            $this->qt($childTable),
                            $this->qt($origName),
                            $this->qt($childCol),
                            $this->qt($table)
                        );
                        if ($onDelete && $onDelete !== 'NO ACTION') {
                            $fkSql .= " ON DELETE {$onDelete}";
                        }
                        if ($onUpdate && $onUpdate !== 'NO ACTION') {
                            $fkSql .= " ON UPDATE {$onUpdate}";
                        }

                        if ($dry) {
                            $output->writeln("[$childTable] DRY contract: WOULD ADD FK `{$origName}` (`{$childCol}`) → `{$table}`(`id`) ON DELETE {$onDelete} ON UPDATE {$onUpdate}");
                        } else {
                            $pdo->exec($fkSql);
                        }
                    }

                    if (! $dry) {
                        $output->writeln("[$table] contract: PK switched to `id`, child FKs rebuilt — deploy your app to use `id` before removing `uuid`");
                    }

                    $results[] = new Log(self::getName(), $table, 'uuid→id', 'contract', $dry ? 'DRY: would switch PK to `id` and rebuild FKs' : 'PK switched to `id`, child FKs rebuilt');
                }

                continue;
            }

            // $idIsPk from here
            $hasUuid = $this->columnExists($pdo, $table, 'uuid');

            if (! $hasUuid) {
                if ($debug) {
                    $output->writeln("[$table] migration already complete");
                }
                continue;
            }

            // --- Remove phase ---
            $shouldRemove = $autoDetect ? true : (bool) $runRemove;
            if (! $shouldRemove) {
                $output->writeln("[$table] `uuid` column still present — set remove: true to drop it once your app no longer references it");
                $results[] = new Log(self::getName(), $table, 'uuid', 'pending remove', 'set remove: true to drop `uuid`');
                continue;
            }

            if ($dry) {
                $output->writeln("[$table] DRY remove: WOULD DROP COLUMN `uuid`");
            } else {
                $pdo->exec(sprintf("ALTER TABLE `%s` DROP COLUMN `uuid`", $this->qt($table)));
                $output->writeln("[$table] remove: `uuid` column dropped — migration complete");
            }

            $results[] = new Log(self::getName(), $table, 'uuid→id', 'remove', $dry ? 'DRY: would drop `uuid`' : '`uuid` column dropped');
        }

        return $results;
    }

    // ---------------- helpers ----------------

    private function likeMatch(string $table, string $likePattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }

    private function columnExists(PDO $pdo, string $table, string $col): bool
    {
        $st = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $st->execute([
            ':t' => $table,
            ':c' => $col,
        ]);
        return (bool) $st->fetchColumn();
    }

    /**
     * @param list<string> $cols
     */
    private function hasIndexOnColumns(PDO $pdo, string $table, array $cols, bool $requireUnique): bool
    {
        $rows = $pdo->query(sprintf("SHOW INDEX FROM `%s`", $this->qt($table)))->fetchAll(PDO::FETCH_ASSOC);
        $byIdx = [];
        foreach ($rows as $r) {
            $key = (string) ($r['Key_name'] ?? '');
            $seq = (int) ($r['Seq_in_index'] ?? 0);
            $col = strtolower((string) ($r['Column_name'] ?? ''));
            $byIdx[$key]['_unique'] = ((int) ($r['Non_unique'] ?? 1) === 0);
            $byIdx[$key][$seq] = $col;
        }
        $needle = array_map('strtolower', $cols);
        foreach ($byIdx as $info) {
            $isUnique = (bool) $info['_unique'];
            if ($requireUnique && ! $isUnique) {
                continue;
            }
            unset($info['_unique']);
            ksort($info);
            if (array_values($info) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getChildFkMeta(PDO $pdo, string $parentTable, string $parentPkCol): array
    {
        $st = $pdo->prepare("
            SELECT
                k.TABLE_NAME,
                k.COLUMN_NAME,
                k.CONSTRAINT_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND rc.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME = :parent
              AND k.REFERENCED_COLUMN_NAME = :pk
        ");
        $st->execute([
            ':parent' => $parentTable,
            ':pk' => $parentPkCol,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return array<string, mixed>|null
     */
    private function getColumnInfo(PDO $pdo, string $table, string $col): ?array
    {
        $st = $pdo->prepare("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $st->execute([
            ':t' => $table,
            ':c' => $col,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isChar36(string $columnType): bool
    {
        return (bool) preg_match('/^char\s*\(\s*36\s*\)/i', trim($columnType));
    }

    private function makeConstraintName(string ...$parts): string
    {
        $base = implode('_', array_map(
            fn($p) => trim(preg_replace('~[^A-Za-z0-9_]+~', '_', $p), '_'),
            $parts
        ));
        if (strlen($base) <= 64) {
            return $base;
        }
        $hash = substr(md5($base), 0, 8);
        $keep = 64 - 1 - strlen($hash);
        return substr($base, 0, $keep) . '_' . $hash;
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}
