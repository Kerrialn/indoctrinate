<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\Integrity\Constraint\ConvertTemporalColumnsToDatetimeRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class ConvertTemporalColumnsToDatetimeRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'convert_temporal_columns_to_datetime';
    }

    public static function getDescription(): string
    {
        return 'Convert DATE/TIMESTAMP to DATETIME, tidy defaults and ON UPDATE.';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function getConstraintClass(): ?string
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

        $onlyTables = array_map('strtolower', (array)($ctx['only_tables'] ?? []));
        $onlyLike = (array)($ctx['only_table_like'] ?? []);
        $skipTables = array_map('strtolower', (array)($ctx['skip_tables'] ?? []));
        $skipLike = (array)($ctx['skip_table_like'] ?? ['%tmp%', '%temp%', '%cache%']);
        $dry = (bool)($ctx['dry'] ?? false);
        $debug = (bool)($ctx['debug'] ?? false);
        $keepCurrentTs = (bool)($ctx['keep_current_timestamp'] ?? false);

        $allow = function (string $table) use ($onlyTables, $onlyLike, $skipTables, $skipLike): bool {
            $t = strtolower($table);
            if ($skipTables && \in_array($t, $skipTables, true)) return false;
            foreach ($skipLike as $pat) if ($this->likeMatch($table, $pat)) return false;
            $hasOnly = ($onlyTables !== [] || $onlyLike !== []);
            if ($hasOnly) {
                if ($onlyTables && \in_array($t, $onlyTables, true)) return true;
                foreach ($onlyLike as $pat) if ($this->likeMatch($table, $pat)) return true;
                return false;
            }
            return true;
        };

        $cols = $this->temporalColumns($pdo);
        $cols = array_values(array_filter($cols, fn($r) => $allow($r['TABLE_NAME'])));

        $out->writeln(sprintf('[%s] converting %d temporal columns to DATETIME', self::getName(), \count($cols)));

        foreach ($cols as $c) {
            $table = $c['TABLE_NAME'];
            $col = $c['COLUMN_NAME'];
            $dtype = strtolower($c['DATA_TYPE']);           // date|datetime|timestamp
            $nullable = strtoupper((string)$c['IS_NULLABLE']) === 'YES';
            $default = $c['COLUMN_DEFAULT'];

            // Already DATETIME → only fix defaults if necessary
            if ($dtype === 'datetime') {
                foreach ($this->buildDefaultFixSql($table, $col, $nullable, $default, false, $keepCurrentTs) as $label => $sql) {
                    $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, $label);
                }
                continue;
            }

            // DATE → DATETIME (time = 00:00:00)
            if ($dtype === 'date') {
                $nullSql = $nullable ? 'NULL' : 'NOT NULL';
                $defSql = $this->normalizeDefaultForDatetime($default, $nullable, false, $keepCurrentTs);
                $alter = sprintf("ALTER TABLE `%s` MODIFY `%s` DATETIME %s %s",
                    $this->qt($table), $this->qt($col), $nullSql, $defSql
                );
                $this->maybeExec($pdo, $out, $dry, $alter, $results, $table, $col, 'MODIFY DATE→DATETIME');

                // ensure time portion is 00:00:00 (explicit)
                $sql = sprintf(
                    "UPDATE `%s` SET `%s` = DATE_FORMAT(`%s`, '%%Y-%%m-%%d 00:00:00') WHERE `%s` IS NOT NULL",
                    $this->qt($table), $this->qt($col), $this->qt($col), $this->qt($col)
                );
                $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, 'set time to 00:00:00');
                continue;
            }

            // TIMESTAMP → DATETIME
            if ($dtype === 'timestamp') {
                $nullSql = $nullable ? 'NULL' : 'NOT NULL';
                $defSql = $this->normalizeDefaultForDatetime($default, $nullable, true, $keepCurrentTs);
                $alter = sprintf("ALTER TABLE `%s` MODIFY `%s` DATETIME %s %s",
                    $this->qt($table), $this->qt($col), $nullSql, $defSql
                );
                // ON UPDATE is dropped implicitly when moving to DATETIME
                $this->maybeExec($pdo, $out, $dry, $alter, $results, $table, $col, 'MODIFY TIMESTAMP→DATETIME');
                continue;
            }
        }

        if ($debug) {
            foreach (array_slice($results, 0, 5) as $log) {
                $out->writeln('  → ' . $log->getMessage());
            }
        }
        return $results;
    }

    /** @return array<string,string> */
    private function buildDefaultFixSql(string $table, string $col, bool $nullable, mixed $default, bool $wasTimestamp, bool $keepCurrentTs): array
    {
        $sqls = [];

        // Normalize empty/zero defaults → drop or NULL
        if ($default === '' || $default === '0000-00-00' || str_starts_with((string)$default, '0000-00-00')) {
            $sqls['drop zero/empty DEFAULT'] = sprintf(
                "ALTER TABLE `%s` ALTER COLUMN `%s` %s",
                $this->qt($table), $this->qt($col), $nullable ? "SET DEFAULT NULL" : "DROP DEFAULT"
            );
            return $sqls;
        }

        if ($wasTimestamp && \is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP' && !$keepCurrentTs) {
            $sqls['drop CURRENT_TIMESTAMP DEFAULT'] = sprintf(
                "ALTER TABLE `%s` ALTER COLUMN `%s` DROP DEFAULT",
                $this->qt($table), $this->qt($col)
            );
        }

        return $sqls;
    }

    private function normalizeDefaultForDatetime(mixed $default, bool $nullable, bool $wasTimestamp, bool $keepCurrentTs): string
    {
        if ($default === null || $default === '' || str_starts_with((string)$default, '0000-00-00')) {
            return $nullable ? 'DEFAULT NULL' : '';
        }
        if ($wasTimestamp && \is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP' && !$keepCurrentTs) {
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
        return (bool)preg_match($re, $table);
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}
