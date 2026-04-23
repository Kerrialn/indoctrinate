<?php

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsurePrimaryKeyUuidRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'ensure_primary_key_uuid';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'ensures that tables have a UUID primary key';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function getConstraintClass(): ?string
    {
        return EnsurePrimaryKeyUuidRuleConstraints::class;
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
        $cascade = (bool) ($context['cascade'] ?? false);

        $allow = function (string $table) use ($onlyTables, $onlyLike, $skipTables, $skipLike): bool {
            $t = strtolower($table);
            if ($skipTables && in_array($t, $skipTables, true)) return false;
            foreach ($skipLike as $pat) if ($this->likeMatch($table, $pat)) return false;

            $hasOnly = ($onlyTables !== [] || $onlyLike !== []);
            if ($hasOnly) {
                if ($onlyTables && in_array($t, $onlyTables, true)) return true;
                foreach ($onlyLike as $pat) if ($this->likeMatch($table, $pat)) return true;
                return false;
            }
            return true;
        };

        // Base tables (filtered)
        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_values(array_filter($tables, $allow));

        $output->writeln(sprintf('[%s] scanning %d table(s)', self::getName(), \count($tables)));

        // Child FK count (to avoid breaking referential integrity)
        $childFks = $this->childFkCounts($pdo);

        foreach ($tables as $table) {
            // What’s the PK situation?
            $pkCols = $this->getPrimaryKeyColumns($pdo, $table);

            if ($pkCols === []) {
                // --- NO PK: add UUID id and make it PK ---
                if ($dry) {
                    $output->writeln("[$table] DRY: WOULD ADD COLUMN `id` CHAR(36) NOT NULL, populate UUID(), ADD PRIMARY KEY (`id`)");
                    $results[] = new Log(self::getName(), $table, 'id', 'missing', 'DRY: would add UUID id and PK');
                } else {
                    $pdo->exec(sprintf("ALTER TABLE `%s` ADD COLUMN `id` CHAR(36) NULL", $this->qt($table)));
                    $pdo->exec(sprintf("UPDATE `%s` SET `id` = UUID()", $this->qt($table)));
                    $pdo->exec(sprintf("ALTER TABLE `%s` MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY (`id`)", $this->qt($table)));
                    $results[] = new Log(self::getName(), $table, 'id', 'missing', 'added UUID id and PK');
                    $output->writeln("[$table] added `id` CHAR(36) UUID and set as PRIMARY KEY");
                }
                continue;
            }

            if (\count($pkCols) > 1) {
                // --- COMPOSITE PK: add surrogate UUID id, keep uniqueness on old cols ---
                $prevColsSql = implode(', ', array_map(fn($c) => "`$c`", $pkCols));

                if ($dry) {
                    $output->writeln("[$table] DRY: WOULD ADD `id` CHAR(36) NOT NULL (populate UUID), DROP PRIMARY KEY, ADD PRIMARY KEY(`id`), ADD UNIQUE ($prevColsSql)");
                    $results[] = new Log(self::getName(), $table, '(composite)', 'present', 'DRY: would replace with UUID PK and unique previous PK');
                } else {
                    if (! $this->columnExists($pdo, $table, 'id')) {
                        $pdo->exec(sprintf("ALTER TABLE `%s` ADD COLUMN `id` CHAR(36) NULL", $this->qt($table)));
                    }
                    $pdo->exec(sprintf("UPDATE `%s` SET `id` = UUID()", $this->qt($table)));
                    $this->removeAutoIncrementIfAny($pdo, $table, $pkCols, $output, $dry);
                    $pdo->exec(sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table)));

                    $pdo->exec(sprintf("ALTER TABLE `%s` MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY (`id`)", $this->qt($table)));
                    $pdo->exec(sprintf("ALTER TABLE `%s` ADD UNIQUE (%s)", $this->qt($table), $prevColsSql));
                    $results[] = new Log(self::getName(), $table, implode('+', $pkCols), 'composite', 'replaced with UUID PK; previous PK unique');
                    $output->writeln("[$table] replaced composite PK with UUID `id`; added UNIQUE($prevColsSql)");
                }
                continue;
            }

            // --- SINGLE-COLUMN PK ---
            $pk = $pkCols[0];
            $pkInfo = $this->getColumnInfo($pdo, $table, $pk);
            $isChar36Pk = $pkInfo && $this->isChar36((string) $pkInfo['COLUMN_TYPE']);
            $isNotNull = $pkInfo && strtoupper((string) $pkInfo['IS_NULLABLE']) === 'NO';
            $isIntLike = $pkInfo && preg_match('/\b(tinyint|smallint|mediumint|int|bigint)\b/i', (string) $pkInfo['COLUMN_TYPE']);

            // 0) Already UUID-shaped PK? (either 'uuid' or 'id' as CHAR(36))
            //    If nullable, tighten to NOT NULL; otherwise skip this table.
            if (($pk === 'uuid' || $pk === 'id') && $isChar36Pk) {
                if (! $isNotNull) {
                    if ($dry) {
                        $output->writeln("[$table] DRY: WOULD ALTER `$pk` to NOT NULL");
                        $results[] = new Log(self::getName(), $table, $pk, 'CHAR(36) NULL', 'DRY: would set NOT NULL');
                    } else {
                        $pdo->exec(sprintf("ALTER TABLE `%s` MODIFY `%s` CHAR(36) NOT NULL", $this->qt($table), $this->qt($pk)));
                        $results[] = new Log(self::getName(), $table, $pk, 'CHAR(36) NULL', 'set NOT NULL');
                        $output->writeln("[$table] set `$pk` to NOT NULL");
                    }
                } else {
                    $results[] = new Log(self::getName(), $table, $pk, 'already CHAR(36) NOT NULL', 'OK');
                }
                continue;
            }

            // 1) PK named 'id' but NOT CHAR(36) → convert IN-PLACE, keep name 'id'
            if ($pk === 'id' && ! $isChar36Pk) {
                $childCount = (int) ($childFks[$table] ?? 0);
                if ($childCount > 0 && ! $cascade) {
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        'id',
                        'int/bigint primary key with child FKs',
                        "skip automatic rewrite to UUID (affects {$childCount} child FK relationship(s)); run with --cascade to migrate"
                    );
                    $output->writeln("[$table] SKIP: {$childCount} child FK(s); re-run with --cascade to migrate.");
                    continue;
                }

                // Helper you added earlier – keeps the column name 'id'
                $this->cascadeIdToUuidKeepingName($pdo, $table, $output, $dry);
                $results[] = new Log(self::getName(), $table, 'id', 'int/bigint PK', 'converted to UUID PRIMARY KEY (kept name id)');
                $output->writeln("[$table] converted integer PK `id` → UUID `id` (kept name)");
                continue;
            }

            // 2) Non-'id' PK:
            //    - If NOT int-like, do nothing.
            //    - If int-like:
            //         • with children and no --cascade → skip & log (handle later in naming rule)
            //         • with children and --cascade   → still skip here to avoid renaming; do in naming rule
            //         • without children              → add UUID `id` and make it PK (drop old PK col)
            if (! $isIntLike) {
                $results[] = new Log(self::getName(), $table, $pk, (string) ($pkInfo['COLUMN_TYPE'] ?? 'unknown'), 'non-integer PK → nothing to do');
                continue;
            }

            $childCount = (int) ($childFks[$table] ?? 0);
            if ($childCount > 0) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    $pk,
                    'int/bigint primary key with child FKs',
                    "skip (non-'id' PK rename would require coordinated FK updates); handle in a later naming rule"
                );
                $output->writeln("[$table] SKIP: non-'id' int PK with {$childCount} child FK(s); handle with naming rule.");
                continue;
            }

            // No children → safe one-table migration: add UUID `id`, make it PK, drop old PK col
            if ($dry) {
                $output->writeln("[$table] DRY: WOULD add `id` CHAR(36), fill UUIDs, drop PK, set PK(id), drop old PK `$pk`");
                $results[] = new Log(self::getName(), $table, $pk, 'int/bigint PK', 'DRY: would convert to UUID PK named id');
            } else {
                if (! $this->columnExists($pdo, $table, 'id')) {
                    $pdo->exec(sprintf("ALTER TABLE `%s` ADD COLUMN `id` CHAR(36) NULL", $this->qt($table)));
                }
                $pdo->exec(sprintf("UPDATE `%s` SET `id` = UUID()", $this->qt($table)));
                $this->removeAutoIncrementIfAny($pdo, $table, [$pk], $output, $dry);
                $pdo->exec(sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($table)));
                $pdo->exec(sprintf("ALTER TABLE `%s` MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY (`id`)", $this->qt($table)));
                $pdo->exec(sprintf("ALTER TABLE `%s` DROP COLUMN `%s`", $this->qt($table), $this->qt($pk)));
                $results[] = new Log(self::getName(), $table, $pk, 'int/bigint PK', 'converted to UUID PRIMARY KEY named id');
                $output->writeln("[$table] converted integer PK `$pk` → UUID PRIMARY KEY `id`");
            }
            continue;
        }

        if ($results !== [] && $debug) {
            foreach (array_slice($results, 0, 5) as $log) {
                if (method_exists($log, 'getMessage')) {
                    $output->writeln('  → ' . $log->getMessage());
                } else {
                    $output->writeln('  → (log entry)');
                }
            }
        }

        return $results;
    }

    private function cascadeToUuid(PDO $pdo, string $parent, string $parentPk, string $parentUuidCol, OutputInterface $out, bool $dry): void
    {
        // Helper to fetch UPDATE/DELETE rules for an FK by name (NO ACTION -> RESTRICT for clarity)
        $getFkRules = function (string $constraintName) use ($pdo): array {
            $st = $pdo->prepare("
            SELECT UPDATE_RULE, DELETE_RULE
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND CONSTRAINT_NAME   = :n
            LIMIT 1
        ");
            $st->execute([
                ':n' => $constraintName,
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $upd = strtoupper((string) ($row['UPDATE_RULE'] ?? 'RESTRICT'));
            $del = strtoupper((string) ($row['DELETE_RULE'] ?? 'RESTRICT'));
            if ($upd === 'NO ACTION') $upd = 'RESTRICT';
            if ($del === 'NO ACTION') $del = 'RESTRICT';
            return [$upd, $del];
        };

        // 1) Ensure parent UUID column exists & backfilled & unique
        if ($dry) {
            if (! $this->columnExists($pdo, $parent, $parentUuidCol)) {
                $out->writeln("[$parent] DRY: WOULD ADD COLUMN `$parentUuidCol` CHAR(36) NULL");
            }
            $out->writeln("[$parent] DRY: WOULD UPDATE `$parent`.`$parentUuidCol` = UUID() WHERE NULL/empty");
            $out->writeln("[$parent] DRY: WOULD ADD UNIQUE KEY on `$parentUuidCol`");
        } else {
            if (! $this->columnExists($pdo, $parent, $parentUuidCol)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `%s` CHAR(36) NULL",
                    $this->qt($parent),
                    $this->qt($parentUuidCol)
                ));
            }
            $pdo->exec(sprintf(
                "UPDATE `%s` SET `%s` = UUID() WHERE `%s` IS NULL OR `%s` = ''",
                $this->qt($parent),
                $this->qt($parentUuidCol),
                $this->qt($parentUuidCol),
                $this->qt($parentUuidCol)
            ));

            if (! $this->hasIndexOnColumns($pdo, $parent, [$parentUuidCol], /*unique*/ true)) {
                $uniqName = $this->makeConstraintName($parent, $parentUuidCol, 'unique');
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD UNIQUE `%s` (`%s`)",
                    $this->qt($parent),
                    $this->qt($uniqName),
                    $this->qt($parentUuidCol)
                ));
            }
        }

        // 2) Children referencing parent(parentPk) – skip already-migrated *_uuid columns
        $children = $this->getChildFkDetails($pdo, $parent, $parentPk);

        // 3) For each child, add shadow UUID FK col, backfill if needed, index it, add FK with preserved rules; drop old FK
        foreach ($children as $fk) {
            $childTable = $fk['TABLE_NAME'];
            $childCol = $fk['COLUMN_NAME'];                 // old INT/BIGINT FK column
            $childUuidCol = $this->makeShadowUuidCol($childCol);
            $fkOldName = $fk['CONSTRAINT_NAME'];

            // Preserve original FK rules if we can
            [$updateRule, $deleteRule] = $getFkRules($fkOldName);

            // Fast path: zero non-null/zero rows -> no backfill required
            $nonNull = $this->countNonNulls($pdo, $childTable, $childCol, /*treatZeroAsNull*/ true);

            if ($dry) {
                if (! $this->columnExists($pdo, $childTable, $childUuidCol)) {
                    $out->writeln("[$childTable] DRY: WOULD ADD `$childUuidCol` CHAR(36) NULL" . ($nonNull === 0 ? " (no data to backfill)" : ""));
                } else {
                    $out->writeln("[$childTable] DRY: `$childUuidCol` already exists");
                }
                if ($nonNull > 0) {
                    $out->writeln("[$childTable] DRY: WOULD backfill `$childUuidCol` via JOIN to `$parent`.`$parentUuidCol`");
                }
                if (! $this->hasIndexOnColumns($pdo, $childTable, [$childUuidCol], false)) {
                    $idxName = $this->makeConstraintName($childTable, $childUuidCol, 'idx');
                    $out->writeln("[$childTable] DRY: WOULD ADD INDEX `$idxName` (`$childUuidCol`)");
                }
                $fkNewName = $this->makeConstraintName($childTable, $childUuidCol, $parent, 'fk');
                if (! $this->fkNameExists($pdo, $fkNewName)) {
                    $out->writeln("[$childTable] DRY: WOULD ADD FK `$fkNewName` (`$childUuidCol`) → `$parent`(`$parentUuidCol`) ON DELETE $deleteRule ON UPDATE $updateRule");
                } else {
                    $out->writeln("[$childTable] DRY: FK `$fkNewName` already exists");
                }
                if ($this->fkNameExists($pdo, $fkOldName)) {
                    $out->writeln("[$childTable] DRY: WOULD DROP OLD FK `$fkOldName`");
                }
                continue;
            }

            // Add shadow column if missing
            if (! $this->columnExists($pdo, $childTable, $childUuidCol)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `%s` CHAR(36) NULL",
                    $this->qt($childTable),
                    $this->qt($childUuidCol)
                ));
            }

            // Backfill only if there is data to map
            if ($nonNull > 0) {
                $pdo->exec(sprintf(
                    "UPDATE `%s` c
                 JOIN `%s` p ON p.`%s` = c.`%s`
                 SET c.`%s` = p.`%s`
                 WHERE c.`%s` IS NULL OR c.`%s` = ''",
                    $this->qt($childTable),
                    $this->qt($parent),
                    $this->qt($parentPk),
                    $this->qt($childCol),
                    $this->qt($childUuidCol),
                    $this->qt($parentUuidCol),
                    $this->qt($childUuidCol),
                    $this->qt($childUuidCol)
                ));
            }

            // Index the shadow column if needed
            if (! $this->hasIndexOnColumns($pdo, $childTable, [$childUuidCol], false)) {
                $idxName = $this->makeConstraintName($childTable, $childUuidCol, 'idx');
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD INDEX `%s` (`%s`)",
                    $this->qt($childTable),
                    $this->qt($idxName),
                    $this->qt($childUuidCol)
                ));
            }

            // Add *new* FK on the shadow column with preserved rules
            $fkNewName = $this->makeConstraintName($childTable, $childUuidCol, $parent, 'fk');
            if (! $this->fkNameExists($pdo, $fkNewName)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) " .
                    "REFERENCES `%s`(`%s`) ON DELETE %s ON UPDATE %s",
                    $this->qt($childTable),
                    $this->qt($fkNewName),
                    $this->qt($childUuidCol),
                    $this->qt($parent),
                    $this->qt($parentUuidCol),
                    $deleteRule,
                    $updateRule
                ));
            }

            // Drop the OLD FK (unblock parent PK flip)
            if ($this->fkNameExists($pdo, $fkOldName)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` DROP FOREIGN KEY `%s`",
                    $this->qt($childTable),
                    $this->qt($fkOldName)
                ));
            }
        }

        // 4) Flip parent: make uuid the PK
        if ($dry) {
            $out->writeln("[$parent] DRY: WOULD DROP PRIMARY KEY, MODIFY `$parentUuidCol` NOT NULL, ADD PRIMARY KEY(`$parentUuidCol`)");
        } else {
            $this->removeAutoIncrementIfAny($pdo, $parent, [$parentPk], $out, $dry);
            $pdo->exec(sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($parent)));
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` MODIFY `%s` CHAR(36) NOT NULL, ADD PRIMARY KEY (`%s`)",
                $this->qt($parent),
                $this->qt($parentUuidCol),
                $this->qt($parentUuidCol)
            ));
        }

        // 5) Finalize children: enforce NOT NULL iff old FK was NOT NULL; drop old int FK column
        foreach ($children as $fk) {
            $childTable = $fk['TABLE_NAME'];
            $childCol = $fk['COLUMN_NAME'];
            $childUuidCol = $this->makeShadowUuidCol($childCol);

            if ($dry) {
                // Peek old nullability
                $info = $this->getColumnInfo($pdo, $childTable, $childCol);
                $oldWasNullable = $info ? (strtoupper((string) $info['IS_NULLABLE']) === 'YES') : true;
                if (! $oldWasNullable) {
                    $out->writeln("[$childTable] DRY: WOULD ensure no NULLs in `$childUuidCol`, then MODIFY NOT NULL");
                } else {
                    $out->writeln("[$childTable] DRY: keep `$childUuidCol` NULLable (old FK was NULLable)");
                }
                if ($this->columnExists($pdo, $childTable, $childCol)) {
                    $out->writeln("[$childTable] DRY: WOULD DROP COLUMN `$childCol`");
                }
                continue;
            }

            $info = $this->getColumnInfo($pdo, $childTable, $childCol);
            $oldWasNullable = $info ? (strtoupper((string) $info['IS_NULLABLE']) === 'YES') : true;

            if (! $oldWasNullable) {
                $nulls = $this->countNulls($pdo, $childTable, $childUuidCol);
                if ($nulls > 0) {
                    throw new \RuntimeException(sprintf(
                        "[%s] Cannot set `%s.%s` NOT NULL: %d NULL row(s) remain after backfill.",
                        self::getName(),
                        $childTable,
                        $childUuidCol,
                        $nulls
                    ));
                }
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` MODIFY `%s` CHAR(36) NOT NULL",
                    $this->qt($childTable),
                    $this->qt($childUuidCol)
                ));
            }

            if ($this->columnExists($pdo, $childTable, $childCol)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` DROP COLUMN `%s`",
                    $this->qt($childTable),
                    $this->qt($childCol)
                ));
            }
        }

        // 6) Drop old parent int PK column (if different)
        if ($parentPk !== $parentUuidCol) {
            if ($dry) {
                $out->writeln("[$parent] DRY: WOULD DROP COLUMN `$parentPk`");
            } else {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` DROP COLUMN `%s`",
                    $this->qt($parent),
                    $this->qt($parentPk)
                ));
            }
        }
    }

    private function countNulls(PDO $pdo, string $table, string $col): int
    {
        $sql = sprintf(
            "SELECT COUNT(*) FROM `%s` WHERE `%s` IS NULL OR `%s` = ''",
            $this->qt($table),
            $this->qt($col),
            $this->qt($col)
        );
        return (int) $pdo->query($sql)->fetchColumn();
    }

    private function likeMatch(string $table, string $likePattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }

    private function makeIndexName(string $table, string $col, string $suffix = 'idx'): string
    {
        return $this->makeConstraintName($table, $col, $suffix);
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

    private function hasIndexOnColumns(PDO $pdo, string $table, array $cols, bool $requireUnique): bool
    {
        $rows = $pdo->query(sprintf("SHOW INDEX FROM `%s`", $this->qt($table)))->fetchAll(PDO::FETCH_ASSOC);
        // group by index name, preserve order
        $byIdx = [];
        foreach ($rows as $r) {
            $key = (string) ($r['Key_name'] ?? '');
            $seq = (int) ($r['Seq_in_index'] ?? 0);
            $col = strtolower((string) ($r['Column_name'] ?? ''));
            $byIdx[$key]['_unique'] = ((int) ($r['Non_unique'] ?? 1) === 0);
            $byIdx[$key][$seq] = $col;
        }
        $needle = array_map('strtolower', $cols);
        foreach ($byIdx as $key => $info) {
            $isUnique = (bool) ($info['_unique'] ?? false);
            if ($requireUnique && ! $isUnique) continue;
            unset($info['_unique']);
            ksort($info);
            $colsInIdx = array_values($info);
            if ($colsInIdx === $needle) return true;
        }
        return false;
    }

    private function getChildFkDetails(PDO $pdo, string $parentTable, string $parentPkCol): array
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
          AND k.COLUMN_NAME NOT LIKE '%\\_uuid%'  -- skip already-migrated columns
    ");
        $st->execute([
            ':parent' => $parentTable,
            ':pk' => $parentPkCol,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

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

    private function removeAutoIncrementIfAny(PDO $pdo, string $table, array $pkCols, OutputInterface $out, bool $dry): void
    {
        foreach ($pkCols as $col) {
            $info = $this->getColumnInfo($pdo, $table, $col);
            if (! $info) continue;
            if (stripos((string) $info['EXTRA'], 'auto_increment') === false) continue;

            $ctype = (string) $info['COLUMN_TYPE'];         // e.g. "bigint unsigned"
            $null = strtoupper((string) $info['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';

            if ($dry) {
                $out->writeln(sprintf(
                    '[%s] DRY: WOULD REMOVE AUTO_INCREMENT from `%s`.`%s` (MODIFY `%s` %s %s)',
                    self::getName(),
                    $table,
                    $col,
                    $col,
                    $ctype,
                    $null
                ));
            } else {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` MODIFY `%s` %s %s",
                    $this->qt($table),
                    $this->qt($col),
                    $ctype,
                    $null
                ));
            }
        }
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }

    private function childFkCounts(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT k.REFERENCED_TABLE_NAME AS parent_table,
                   COUNT(DISTINCT CONCAT(k.TABLE_NAME, ':', k.CONSTRAINT_NAME)) AS c
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            GROUP BY k.REFERENCED_TABLE_NAME
        ")->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) $out[$r['parent_table']] = (int) $r['c'];
        return $out;
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
        $stmt->execute([
            ':t' => $table,
        ]);
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
        $stmt->execute([
            ':t' => $table,
        ]);
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
        return (bool) preg_match('/^char\s*\(\s*36\s*\)/i', trim($columnType));
    }

    /**
     * Build a globally-unique, MySQL-safe constraint/index name (<= 64 chars).
     * If exceeded, suffix with an 8-char hash.
     */
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

    private function fkNameExists(PDO $pdo, string $constraintName): bool
    {
        $st = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND CONSTRAINT_NAME = :n
            LIMIT 1
        ");
        $st->execute([
            ':n' => $constraintName,
        ]);
        return (bool) $st->fetchColumn();
    }

    private function countNonNulls(PDO $pdo, string $table, string $col, bool $treatZeroAsNull = true): int
    {
        $cond = $treatZeroAsNull
            ? sprintf("`%s` IS NOT NULL AND `%s` <> 0", $this->qt($col), $this->qt($col))
            : sprintf("`%s` IS NOT NULL", $this->qt($col));

        $sql = sprintf("SELECT COUNT(*) FROM `%s` WHERE %s", $this->qt($table), $cond);
        return (int) $pdo->query($sql)->fetchColumn();
    }

    private function makeShadowUuidCol(string $col): string
    {
        return preg_match('~_id$~i', $col) ? preg_replace('~_id$~i', '_uuid', $col) : ($col . '_uuid');
    }

    /**
     * Convert a parent table whose PK is `id` (INT/BIGINT) to `id` (CHAR(36) UUID),
     * keeping the column name `id` the same across parent and children.
     *
     * Steps:
     *  - Parent: add `id_uuid_tmp` (CHAR(36)), fill UUIDs, add UNIQUE.
     *  - Children: for each FK column `<col>` -> parent.id, add `<col>_tmp` (CHAR(36)),
     *    backfill via join to parent.id_uuid_tmp, index it, add parallel FK to parent.id_uuid_tmp,
     *    drop old FK on `<col>`.
     *  - Flip parent: drop PK on id, remove AUTO_INCREMENT, rename id -> id_old (preserve exact type),
     *    promote id_uuid_tmp -> id (CHAR(36) NOT NULL), add PK(id), drop id_old.
     *  - Children: if old `<col>` was NOT NULL, ensure `<col>_tmp` has no NULLs then make NOT NULL,
     *    drop old `<col>`, rename `<col>_tmp` -> `<col>`, recreate final FK to parent.id.
     */
    private function cascadeIdToUuidKeepingName(PDO $pdo, string $parent, OutputInterface $out, bool $dry): void
    {
        $parentPk = 'id';
        // 1) Parent: ensure id_uuid_tmp exists, backfilled, and UNIQUE
        $tmpParent = 'id_uuid_tmp';

        if ($dry) {
            if (! $this->columnExists($pdo, $parent, $tmpParent)) {
                $out->writeln("[$parent] DRY: WOULD ADD COLUMN `$tmpParent` CHAR(36) NULL");
            }
            $out->writeln("[$parent] DRY: WOULD UPDATE `$parent`.`$tmpParent` = UUID() WHERE NULL/empty");
            $out->writeln("[$parent] DRY: WOULD ADD UNIQUE KEY on `$tmpParent`");
        } else {
            if (! $this->columnExists($pdo, $parent, $tmpParent)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `%s` CHAR(36) NULL",
                    $this->qt($parent),
                    $this->qt($tmpParent)
                ));
            }
            $pdo->exec(sprintf(
                "UPDATE `%s` SET `%s` = UUID() WHERE `%s` IS NULL OR `%s` = ''",
                $this->qt($parent),
                $this->qt($tmpParent),
                $this->qt($tmpParent),
                $this->qt($tmpParent)
            ));
            // UNIQUE so children can FK to it pre-flip
            if (! $this->hasIndexOnColumns($pdo, $parent, [$tmpParent], /*unique*/ true)) {
                $uniq = $this->makeConstraintName($parent, $tmpParent, 'uniq');
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD UNIQUE `%s` (`%s`)",
                    $this->qt($parent),
                    $this->qt($uniq),
                    $this->qt($tmpParent)
                ));
            }
        }

        // 2) Children referencing parent(id)
        $children = $this->getChildFkDetails($pdo, $parent, $parentPk); // returns TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
        foreach ($children as $fk) {
            $childTable = $fk['TABLE_NAME'];
            $childCol = $fk['COLUMN_NAME'];      // old INT/BIGINT FK column
            $fkOldName = $fk['CONSTRAINT_NAME'];

            // temp shadow col for child FK values (CHAR(36))
            $tmpChildCol = $childCol . '_tmp';

            if ($dry) {
                if (! $this->columnExists($pdo, $childTable, $tmpChildCol)) {
                    $out->writeln("[$childTable] DRY: WOULD ADD COLUMN `$tmpChildCol` CHAR(36) NULL");
                }
                $out->writeln("[$childTable] DRY: WOULD BACKFILL `$tmpChildCol` from `$childCol` via parent.`$tmpParent`");
                $idx = $this->makeConstraintName($childTable, $tmpChildCol, 'idx');
                $out->writeln("[$childTable] DRY: WOULD ADD INDEX `$idx`(`$tmpChildCol`)");
                $fkParallel = $this->makeConstraintName($childTable, $tmpChildCol, $parent, 'uuid_fk');
                $out->writeln("[$childTable] DRY: WOULD ADD PARALLEL FK `$fkParallel` (`$tmpChildCol`) → `$parent`(`$tmpParent`)");
                $out->writeln("[$childTable] DRY: WOULD DROP OLD FK `$fkOldName`");
                continue;
            }

            // add temp char(36) column if missing
            if (! $this->columnExists($pdo, $childTable, $tmpChildCol)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `%s` CHAR(36) NULL",
                    $this->qt($childTable),
                    $this->qt($tmpChildCol)
                ));
            }

            // backfill via join on *legacy* int id → tmp UUID on parent
            $pdo->exec(sprintf(
                "UPDATE `%s` c
             JOIN `%s` p ON p.`%s` = c.`%s`
                SET c.`%s` = p.`%s`
              WHERE c.`%s` IS NULL OR c.`%s` = ''",
                $this->qt($childTable),
                $this->qt($parent),
                $this->qt($parentPk),
                $this->qt($childCol),
                $this->qt($tmpChildCol),
                $this->qt($tmpParent),
                $this->qt($tmpChildCol),
                $this->qt($tmpChildCol)
            ));

            // index temp col
            if (! $this->hasIndexOnColumns($pdo, $childTable, [$tmpChildCol], false)) {
                $idx = $this->makeConstraintName($childTable, $tmpChildCol, 'idx');
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD INDEX `%s` (`%s`)",
                    $this->qt($childTable),
                    $this->qt($idx),
                    $this->qt($tmpChildCol)
                ));
            }

            // parallel FK to parent.tmp UUID (needs UNIQUE on parent tmp col)
            $fkParallel = $this->makeConstraintName($childTable, $tmpChildCol, $parent, 'uuid_fk');
            if (! $this->fkNameExists($pdo, $fkParallel)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`)",
                    $this->qt($childTable),
                    $this->qt($fkParallel),
                    $this->qt($tmpChildCol),
                    $this->qt($parent),
                    $this->qt($tmpParent)
                ));
            }

            // drop old FK on legacy int col
            if ($this->fkNameExists($pdo, $fkOldName)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` DROP FOREIGN KEY `%s`",
                    $this->qt($childTable),
                    $this->qt($fkOldName)
                ));
            }
        }

        // 3) Flip parent id → UUID (but keep the name `id`)
        if ($dry) {
            $out->writeln("[$parent] DRY: WOULD DROP PRIMARY KEY on `id`, REMOVE AUTO_INCREMENT, RENAME `id`→`id_old`, promote `$tmpParent`→`id` (CHAR(36) NOT NULL), ADD PRIMARY KEY(`id`), DROP `id_old`");
        } else {
            // capture old column type/nullability to rename safely
            $idInfo = $this->getColumnInfo($pdo, $parent, $parentPk); // COLUMN_TYPE, IS_NULLABLE, EXTRA
            if (! $idInfo) {
                throw new \RuntimeException("[$parent] cannot read column info for `id`");
            }

            // remove auto_increment if present
            $this->removeAutoIncrementIfAny($pdo, $parent, [$parentPk], $out, $dry);

            // drop PK so we can change types/names
            $pdo->exec(sprintf("ALTER TABLE `%s` DROP PRIMARY KEY", $this->qt($parent)));

            // rename id -> id_old (preserve original type exactly)
            $oldType = $idInfo['COLUMN_TYPE']; // e.g. "bigint unsigned"
            $oldNull = (strtoupper((string) $idInfo['IS_NULLABLE']) === 'YES') ? 'NULL' : 'NOT NULL';
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` CHANGE `%s` `id_old` %s %s",
                $this->qt($parent),
                $this->qt($parentPk),
                $oldType,
                $oldNull
            ));

            // promote tmp UUID to final `id`
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` CHANGE `%s` `%s` CHAR(36) NOT NULL",
                $this->qt($parent),
                $this->qt($tmpParent),
                $this->qt($parentPk)
            ));

            // (re)add PK on id
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` ADD PRIMARY KEY (`%s`)",
                $this->qt($parent),
                $this->qt($parentPk)
            ));

            // drop id_old (we're *keeping the name* `id` as the UUID)
            $pdo->exec(sprintf("ALTER TABLE `%s` DROP COLUMN `id_old`", $this->qt($parent)));
        }

        // 4) Finalize children: move *_tmp → original name, restore FK to parent.id
        foreach ($children as $fk) {
            $childTable = $fk['TABLE_NAME'];
            $childCol = $fk['COLUMN_NAME'];      // legacy int col (still present)
            $tmpChildCol = $childCol . '_tmp';

            // Determine previous nullability to carry forward
            $childColInfo = $this->getColumnInfo($pdo, $childTable, $childCol);
            $oldWasNullable = true;
            if ($childColInfo && isset($childColInfo['IS_NULLABLE'])) {
                $oldWasNullable = (strtoupper((string) $childColInfo['IS_NULLABLE']) === 'YES');
            }

            if ($dry) {
                if (! $oldWasNullable) {
                    $out->writeln("[$childTable] DRY: WOULD ENSURE `$tmpChildCol` has no NULLs, then MODIFY `$tmpChildCol` NOT NULL");
                }
                $finalFk = $this->makeConstraintName($childTable, $childCol, $parent, 'id_fk');
                $out->writeln("[$childTable] DRY: WOULD DROP COLUMN `$childCol`, RENAME `$tmpChildCol`→`$childCol` (CHAR(36) [NOT NULL if old not null]), ADD FINAL FK `$finalFk` (`$childCol`) → `$parent`(`id`)");
                continue;
            }

            // tighten NOT NULL if the old col was NOT NULL
            if (! $oldWasNullable) {
                $nulls = $this->countNulls($pdo, $childTable, $tmpChildCol);
                if ($nulls > 0) {
                    throw new \RuntimeException(sprintf(
                        "[%s] Cannot set `%s.%s` NOT NULL: %d NULL row(s) remain after backfill.",
                        self::getName(),
                        $childTable,
                        $tmpChildCol,
                        $nulls
                    ));
                }
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` MODIFY `%s` CHAR(36) NOT NULL",
                    $this->qt($childTable),
                    $this->qt($tmpChildCol)
                ));
            }

            // drop old legacy int FK column and rename tmp → original name
            if ($this->columnExists($pdo, $childTable, $childCol)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` DROP COLUMN `%s`",
                    $this->qt($childTable),
                    $this->qt($childCol)
                ));
            }
            // rename tmp to original FK column name
            $newNullSql = $oldWasNullable ? 'NULL' : 'NOT NULL';
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` CHANGE `%s` `%s` CHAR(36) %s",
                $this->qt($childTable),
                $this->qt($tmpChildCol),
                $this->qt($childCol),
                $newNullSql
            ));

            // add final FK child.<col> → parent.id (now CHAR(36) PK)
            $finalFk = $this->makeConstraintName($childTable, $childCol, $parent, 'id_fk');
            if (! $this->fkNameExists($pdo, $finalFk)) {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`)",
                    $this->qt($childTable),
                    $this->qt($finalFk),
                    $this->qt($childCol),
                    $this->qt($parent),
                    $this->qt($parentPk)
                ));
            }
        }
    }
}