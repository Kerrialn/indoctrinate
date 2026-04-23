<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\NormalizeTemporalValuesRuleConstraints;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class NormalizeTemporalValuesRule implements RuleInterface
{
    public static function getName(): string
    {
        return 'normalize_temporal_values';
    }

    public static function getDriver(): string
    {
        return 'mysql';
    }

    public static function getDescription(): string
    {
        return 'Normalise legacy/invalid DATE/DATETIME/TIMESTAMP values.';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function getConstraintClass(): ?string
    {
        return NormalizeTemporalValuesRuleConstraints::class;
    }

    public static function isDestructive(): bool
    {
        return false;
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

        $zeroStrategy = (string) ($ctx['zero_date_strategy'] ?? 'null'); // 'null'|'min'
        $minDateTime = (string) ($ctx['min_datetime'] ?? '1970-01-01 00:00:00');

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

        $out->writeln(sprintf('[%s] normalising %d temporal columns', self::getName(), \count($cols)));

        foreach ($cols as $c) {
            $table = $c['TABLE_NAME'];
            $col = $c['COLUMN_NAME'];
            $dtype = strtolower($c['DATA_TYPE']);          // date|datetime|timestamp
            $nullable = strtoupper((string) $c['IS_NULLABLE']) === 'YES';

            // 1) Empty string → NULL (where allowed)
            if ($nullable) {
                $sql = sprintf(
                    "UPDATE `%s` SET `%s` = NULL WHERE `%s` = ''",
                    $this->qt($table),
                    $this->qt($col),
                    $this->qt($col)
                );
                $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, "empty-string → NULL");
            }

            // 2) Zero dates → NULL or min sentinel
            $zeroPatterns = [
                'date' => ["'0000-00-00'"],
                'datetime' => ["'0000-00-00 00:00:00'", "'0000-00-00 00:00:00.000000'"],
                'timestamp' => ["'0000-00-00 00:00:00'", "'0000-00-00 00:00:00.000000'"],
            ];
            $repls = ($zeroStrategy === 'null')
                ? ($nullable ? "NULL" : "'" . ($dtype === 'date' ? substr($minDateTime, 0, 10) : $minDateTime) . "'")
                : ($dtype === 'date' ? "'" . substr($minDateTime, 0, 10) . "'" : "'{$minDateTime}'");

            foreach ($zeroPatterns[$dtype] ?? [] as $p) {
                $sql = sprintf(
                    "UPDATE `%s` SET `%s` = %s WHERE `%s` = %s",
                    $this->qt($table),
                    $this->qt($col),
                    $repls,
                    $this->qt($col),
                    $p
                );
                $label = ($zeroStrategy === 'null' ? 'zero→NULL' : "zero→{$repls}");
                $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, $label);
            }

            // 3) Strip microseconds like '...00:00:00.000000'
            if ($dtype !== 'date') {
                $sql = sprintf(
                    "UPDATE `%s` SET `%s` = DATE_FORMAT(`%s`, '%%Y-%%m-%%d %%H:%%i:%%s') WHERE `%s` LIKE '%%%%.%%%%%%'",
                    $this->qt($table),
                    $this->qt($col),
                    $this->qt($col),
                    $this->qt($col)
                );
                $this->maybeExec($pdo, $out, $dry, $sql, $results, $table, $col, 'strip microseconds');
            }

            // 4) Zero/empty defaults will be normalized in the type-conversion rule
        }

        if ($debug) {
            foreach (array_slice($results, 0, 5) as $log) {
                $out->writeln('  → ' . $log->getMessage());
            }
        }
        return $results;
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
        $cnt = $pdo->exec($sql);
        $out->writeln("[$table.$col] $what" . ($cnt !== false ? " ($cnt rows)" : ""));
        $results[] = new Log(self::getName(), $table, $col, 'normalized', "$what" . ($cnt !== false ? " ($cnt rows)" : ""));
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
