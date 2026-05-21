<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\ConvertTemporalColumnsToDatetimeRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class ConvertTemporalColumnsToDatetimeRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'convert_temporal_columns_to_datetime';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'Converts DATE/TIMESTAMP columns to DATETIME using Expand/Contract. Fixes bad defaults on existing DATETIME columns in-place.';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function getConstraintClass(): string
    {
        return ConvertTemporalColumnsToDatetimeRuleConstraints::class;
    }

    public static function isDestructive(): bool
    {
        return true;
    }

    public function apply(PDO $pdo, OutputInterface $out, array $ctx = []): array
    {
        $results = [];

        $onlyTables = array_map('strtolower', (array) ($ctx['only_tables'] ?? []));
        $onlyLike = (array) ($ctx['only_table_like'] ?? []);
        $skipTables = array_map('strtolower', (array) ($ctx['skip_tables'] ?? []));
        $skipLike = (array) ($ctx['skip_table_like'] ?? ['%tmp%', '%temp%', '%cache%']);
        $dry = (bool) ($ctx['dry'] ?? false);
        $debug = (bool) ($ctx['debug'] ?? false);
        $keepCurrentTs = (bool) ($ctx['keep_current_timestamp'] ?? false);

        // Phase flags — null means auto-detect from DB state.
        $runExpand = isset($ctx['expand']) ? (bool) $ctx['expand'] : null;
        $runContract = isset($ctx['contract']) ? (bool) $ctx['contract'] : null;
        $runRemove = isset($ctx['remove']) ? (bool) $ctx['remove'] : null;
        $autoDetect = ($runExpand === null && $runContract === null && $runRemove === null);

        $allow = function (string $table) use ($onlyTables, $onlyLike, $skipTables, $skipLike): bool {
            $t = strtolower($table);
            if ($skipTables && \in_array($t, $skipTables, true)) {
                return false;
            }
            foreach ($skipLike as $pat) {
                if ($this->likeMatch($table, $pat)) {
                    return false;
                }
            }
            $hasOnly = ($onlyTables !== [] || $onlyLike !== []);
            if ($hasOnly) {
                if ($onlyTables && \in_array($t, $onlyTables, true)) {
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

        $cols = $this->temporalColumns($pdo);
        $cols = array_values(array_filter($cols, fn ($r) => $allow($r['TABLE_NAME'])));

        // Build a lookup of DATE/TIMESTAMP source columns per table so we can
        // skip their _dt migration helpers — those are managed by this rule and
        // should not be processed independently by the default-fixer branch.
        $sourceColsByTable = [];
        foreach ($cols as $c) {
            $dt = strtolower($c['DATA_TYPE']);
            if ($dt === 'date' || $dt === 'timestamp') {
                $sourceColsByTable[$c['TABLE_NAME']][$c['COLUMN_NAME']] = true;
            }
        }

        $cols = array_values(array_filter($cols, function (array $c) use ($sourceColsByTable): bool {
            if (strtolower($c['DATA_TYPE']) !== 'datetime') {
                return true;
            }
            // Skip DATETIME columns that end with _dt and whose base name is a
            // DATE/TIMESTAMP column in the same table — they are _dt migration helpers.
            $name = $c['COLUMN_NAME'];
            if (substr($name, -3) === '_dt') {
                $base = substr($name, 0, -3);
                if (isset($sourceColsByTable[$c['TABLE_NAME']][$base])) {
                    return false;
                }
            }
            return true;
        }));

        $out->writeln(sprintf('[%s] scanning %d temporal column(s)', self::getName(), \count($cols)));

        foreach ($cols as $c) {
            $table = $c['TABLE_NAME'];
            $col = $c['COLUMN_NAME'];
            $dtype = strtolower($c['DATA_TYPE']);
            $nullable = strtoupper((string) $c['IS_NULLABLE']) === 'YES';
            $default = $c['COLUMN_DEFAULT'];

            // Already DATETIME — fix defaults in-place only (metadata-only ALTER, no E/C needed).
            if ($dtype === 'datetime') {
                foreach ($this->buildDefaultFixSql($table, $col, $nullable, $default, false, $keepCurrentTs) as $label => $sql) {
                    $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, $label);
                }
                continue;
            }

            // DATE or TIMESTAMP — use Expand/Contract so we never MODIFY the live column.
            $dtCol = $col . '_dt';

            $hasNewCol = $this->columnExists($pdo, $table, $dtCol);

            if ($debug) {
                $out->writeln("[$table.$col] dtype={$dtype}, {$dtCol} exists=" . ($hasNewCol ? 'yes' : 'no'));
            }

            if ($autoDetect) {
                if (! $hasNewCol) {
                    $this->doExpand($pdo, $out, $dry, $table, $col, $dtCol, $dtype, $results);
                } else {
                    $this->doRemove($pdo, $out, $dry, $table, $col, $dtCol, $dtype, $nullable, $default, $keepCurrentTs, $results);
                }
                continue;
            }

            // Explicit phase flags — run whichever are requested, in expand→contract→remove order.
            if ($runExpand && ! $hasNewCol) {
                $this->doExpand($pdo, $out, $dry, $table, $col, $dtCol, $dtype, $results);
                $hasNewCol = ! $dry;
            }

            if ($runContract && $hasNewCol) {
                $this->doContract($pdo, $out, $dry, $table, $col, $dtCol, $dtype, $results);
            }

            if ($runRemove && $hasNewCol) {
                $this->doRemove($pdo, $out, $dry, $table, $col, $dtCol, $dtype, $nullable, $default, $keepCurrentTs, $results);
            }
        }

        return $results;
    }

    // ── Expand ────────────────────────────────────────────────────────────────

    /**
     * @param array<int, Log> $results
     */
    private function doExpand(PDO $pdo, OutputInterface $out, bool $dry, string $table, string $col, string $dtCol, string $dtype, array &$results): void
    {
        if ($dry) {
            $out->writeln("[$table.$col] DRY expand: WOULD ADD `{$dtCol}` DATETIME NULL and backfill from `{$col}`");
        } else {
            $pdo->exec(sprintf("ALTER TABLE `%s` ADD COLUMN `%s` DATETIME NULL", $this->qt($table), $this->qt($dtCol)));
            $pdo->exec(sprintf(
                "UPDATE `%s` SET `%s` = `%s` WHERE `%s` IS NOT NULL",
                $this->qt($table),
                $this->qt($dtCol),
                $this->qt($col),
                $this->qt($col)
            ));
            $out->writeln("[$table.$col] expand: `{$dtCol}` added and backfilled — deploy your app, then run contract or remove when ready");
        }

        $results[] = new Log(self::getName(), $table, $col, 'expand', $dry ? "DRY: would add `{$dtCol}` and backfill" : "added `{$dtCol}`, backfilled from `{$col}`");
    }

    // ── Contract ──────────────────────────────────────────────────────────────

    /**
     * Re-syncs {col}_dt from {col} without making any structural changes.
     * Safe to run repeatedly while the app is still writing to the old column.
     *
     * @param array<int, Log> $results
     */
    private function doContract(PDO $pdo, OutputInterface $out, bool $dry, string $table, string $col, string $dtCol, string $dtype, array &$results): void
    {
        if ($dry) {
            $out->writeln("[$table.$col] DRY contract: WOULD re-sync `{$dtCol}` from `{$col}`");
        } else {
            $pdo->exec(sprintf(
                "UPDATE `%s` SET `%s` = `%s` WHERE `%s` IS NOT NULL",
                $this->qt($table),
                $this->qt($dtCol),
                $this->qt($col),
                $this->qt($col)
            ));
            $out->writeln("[$table.$col] contract: `{$dtCol}` re-synced from `{$col}`");
        }

        $results[] = new Log(self::getName(), $table, $col, 'contract', $dry ? "DRY: would re-sync `{$dtCol}`" : "re-synced `{$dtCol}` from `{$col}`");
    }

    // ── Remove ────────────────────────────────────────────────────────────────

    /**
     * Final re-sync then atomic drop+rename so {col} is never absent.
     *
     * @param mixed $default
     * @param array<int, Log> $results
     */
    private function doRemove(PDO $pdo, OutputInterface $out, bool $dry, string $table, string $col, string $dtCol, string $dtype, bool $nullable, $default, bool $keepCurrentTs, array &$results): void
    {
        $wasTimestamp = ($dtype === 'timestamp');
        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        $defSql = $this->normalizeDefaultForDatetime($default, $nullable, $wasTimestamp, $keepCurrentTs);
        $colDef = trim("DATETIME {$nullSql} {$defSql}");

        if ($dry) {
            $out->writeln("[$table.$col] DRY remove: WOULD re-sync `{$dtCol}`, then atomically DROP `{$col}` and RENAME `{$dtCol}` → `{$col}` ({$colDef})");
        } else {
            // Final re-sync before the swap.
            $pdo->exec(sprintf(
                "UPDATE `%s` SET `%s` = `%s` WHERE `%s` IS NOT NULL",
                $this->qt($table),
                $this->qt($dtCol),
                $this->qt($col),
                $this->qt($col)
            ));

            // Atomic: drop old column and rename new column to take its place.
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` DROP COLUMN `%s`, CHANGE COLUMN `%s` `%s` %s",
                $this->qt($table),
                $this->qt($col),
                $this->qt($dtCol),
                $this->qt($col),
                $colDef
            ));

            $out->writeln("[$table.$col] remove: `{$col}` is now DATETIME — migration complete");
        }

        $results[] = new Log(self::getName(), $table, $col, 'remove', $dry ? "DRY: would swap `{$dtCol}` → `{$col}` DATETIME" : "`{$col}` converted to DATETIME");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
     * @return array<string,string>
     * @param mixed $default
     */
    private function buildDefaultFixSql(string $table, string $col, bool $nullable, $default, bool $wasTimestamp, bool $keepCurrentTs): array
    {
        $sqls = [];

        if ($default === '' || $default === '0000-00-00' || strncmp((string) $default, '0000-00-00', strlen('0000-00-00')) === 0) {
            $sqls['drop zero/empty DEFAULT'] = sprintf(
                "ALTER TABLE `%s` ALTER COLUMN `%s` %s",
                $this->qt($table),
                $this->qt($col),
                $nullable ? 'SET DEFAULT NULL' : 'DROP DEFAULT'
            );
            return $sqls;
        }

        if ($wasTimestamp && \is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP' && ! $keepCurrentTs) {
            $sqls['drop CURRENT_TIMESTAMP DEFAULT'] = sprintf(
                "ALTER TABLE `%s` ALTER COLUMN `%s` DROP DEFAULT",
                $this->qt($table),
                $this->qt($col)
            );
        }

        return $sqls;
    }

    /**
     * @param mixed $default
     */
    private function normalizeDefaultForDatetime($default, bool $nullable, bool $wasTimestamp, bool $keepCurrentTs): string
    {
        if ($default === null || $default === '' || strncmp((string) $default, '0000-00-00', strlen('0000-00-00')) === 0) {
            return $nullable ? 'DEFAULT NULL' : '';
        }
        if ($wasTimestamp && \is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP' && ! $keepCurrentTs) {
            return $nullable ? 'DEFAULT NULL' : '';
        }
        if (\is_string($default) && strtoupper($default) !== 'CURRENT_TIMESTAMP') {
            return "DEFAULT '" . $default . "'";
        }
        if (\is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP') {
            return $keepCurrentTs ? 'DEFAULT CURRENT_TIMESTAMP' : ($nullable ? 'DEFAULT NULL' : '');
        }
        return $nullable ? 'DEFAULT NULL' : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function temporalColumns(PDO $pdo): array
    {
        $st = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE IN ('date','datetime','timestamp')
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, Log> $results
     */
    private function maybeExec(PDO $pdo, OutputInterface $out, bool $dry, string $sql, array &$results, string $table, string $col, string $what): void
    {
        if ($dry) {
            $out->writeln("[$table.$col] DRY: WOULD $what ($sql)");
            $results[] = new Log(self::getName(), $table, $col, 'dry-run', "would $what");
            return;
        }
        $pdo->exec($sql);
        $out->writeln("[$table.$col] $what");
        $results[] = new Log(self::getName(), $table, $col, 'converted', $what);
    }

    private function likeMatch(string $table, string $likePattern): bool
    {
        $re = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '~')) . '$~i';
        return (bool) preg_match($re, $table);
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}
