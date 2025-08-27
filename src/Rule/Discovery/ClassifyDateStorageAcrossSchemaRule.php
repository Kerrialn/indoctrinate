<?php

namespace DbFixer\Rule\Discovery;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class ClassifyDateStorageAcrossSchemaRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'classify_date_storage_across_schema';
    }

    public static function getCategory(): string
    {
        return 'Discovery';
    }

    public static function isDestructive(): bool
    {
        return false; // reporting only
    }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $results = [];

        // Heuristics: adjust as you like
        $nameLike = $context['name_like'] ?? [
            '%date%', '%time%', '%at%', '%created%', '%updated%', '%timestamp%',
            '%expires%', '%expiry%', '%published%', '%scheduled%', '%dob%', '%birthday%'
        ];
        $previewLimit = (int)($context['preview_limit'] ?? 3);

        $nameWhere = implode(' OR ', array_map(function ($p) use ($pdo) {
            return "LOWER(c.COLUMN_NAME) LIKE " . $this->q($p);
        }, $nameLike));

        // Candidates: either native date/time types, or “suspicious” types by name
        $sql = "
            SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.COLUMN_TYPE, c.IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS c
            JOIN INFORMATION_SCHEMA.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND (
                    c.DATA_TYPE IN ('date','datetime','timestamp')
                 OR (($nameWhere) AND c.DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','int','bigint'))
              )
        ";

        $cols = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $output->writeln(sprintf('[%s] scanned %d candidate columns', self::getName(), \count($cols)));

        // Global summary tallies
        $summary = [
            'native_datetime' => 0,
            'zero_date'       => 0,
            'unix_seconds'    => 0,
            'unix_millis'     => 0,
            'mysql_datetime'  => 0,
            'mysql_date'      => 0,
            'iso8601'         => 0,
            'ddmmyyyy'        => 0,
            'other'           => 0,
        ];

        foreach ($cols as $c) {
            $table = $c['TABLE_NAME'];
            $col   = $c['COLUMN_NAME'];
            $dtype = strtolower($c['DATA_TYPE']);
            $ctype = $c['COLUMN_TYPE'];

            // Native storage shortcut
            if (in_array($dtype, ['date','datetime','timestamp'], true)) {
                // Count zero-date usage for native types
                $zeroSql = sprintf(
                    "SELECT SUM(%s IN ('0000-00-00','0000-00-00 00:00:00')) AS zeros FROM `%s`",
                    $this->col($col, $dtype),
                    $this->qt($table)
                );
                $zeros = (int)($pdo->query($zeroSql)->fetchColumn() ?: 0);

                $summary['native_datetime']++;
                if ($zeros > 0) {
                    $summary['zero_date']++;
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        $col,
                        sprintf('native %s (zero-date rows=%d)', strtoupper($dtype), $zeros),
                        'DROP zero-date defaults; update bad rows to NULL or a valid timestamp'
                    );
                } else {
                    $results[] = new Log(
                        self::getName(),
                        $table,
                        $col,
                        'native ' . strtoupper($dtype),
                        'OK (native date/time storage)'
                    );
                }
                continue;
            }

            // For text/number storage, classify patterns with one aggregation pass
            $aggSql = sprintf("
                SELECT
                    COUNT(*) AS total,
                    SUM(%1\$s IS NULL OR %1\$s = '') AS null_or_empty,
                    SUM(%1\$s REGEXP '^[0-9]{10}$') AS unix10,
                    SUM(%1\$s REGEXP '^[0-9]{13}$') AS unix13,
                    SUM(%1\$s REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$') AS mysql_datetime,
                    SUM(%1\$s REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$') AS mysql_date,
                    SUM(%1\$s REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt ][0-9]{2}:[0-9]{2}(:[0-9]{2})?([.][0-9]+)?([Zz]|[+-][0-9]{2}(:?[0-9]{2})?)$') AS iso8601,
                    SUM(%1\$s REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$') AS ddmmyyyy
                FROM `%2\$s`
            ",
                $this->col($col, $dtype),
                $this->qt($table)
            );

            $agg = $pdo->query($aggSql)->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int)($agg['total'] ?? 0);

            // Decide dominant class
            $classMap = [
                'unix_seconds'   => (int)($agg['unix10'] ?? 0),
                'unix_millis'    => (int)($agg['unix13'] ?? 0),
                'mysql_datetime' => (int)($agg['mysql_datetime'] ?? 0),
                'mysql_date'     => (int)($agg['mysql_date'] ?? 0),
                'iso8601'        => (int)($agg['iso8601'] ?? 0),
                'ddmmyyyy'       => (int)($agg['ddmmyyyy'] ?? 0),
            ];
            arsort($classMap);
            $topClass = key($classMap);
            $topCount = current($classMap) ?: 0;

            $classified = 'other';
            $recommend  = 'review column';

            if ($topCount > 0) {
                $classified = $topClass;
                switch ($topClass) {
                    case 'unix_seconds':
                        $recommend = 'convert to DATETIME using FROM_UNIXTIME(col) and change type to DATETIME';
                        break;
                    case 'unix_millis':
                        $recommend = 'convert to DATETIME using FROM_UNIXTIME(col/1000) and change type to DATETIME';
                        break;
                    case 'mysql_datetime':
                        $recommend = 'change type to DATETIME (values already in MySQL datetime string)';
                        break;
                    case 'mysql_date':
                        $recommend = 'change type to DATE';
                        break;
                    case 'iso8601':
                        $recommend = 'normalize on write/read or migrate to DATETIME; parse ISO-8601';
                        break;
                    case 'ddmmyyyy':
                        $recommend = 'migrate to DATE using STR_TO_DATE(col, "%d/%m/%Y")';
                        break;
                }
            }

            $summary[$classified] = ($summary[$classified] ?? 0) + 1;

            // Pull a few sample values
            $samples = [];
            if ($topCount > 0) {
                $regex = match ($topClass) {
                    'unix_seconds'   => '^[0-9]{10}$',
                    'unix_millis'    => '^[0-9]{13}$',
                    'mysql_datetime' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
                    'mysql_date'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
                    'iso8601'        => '^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt ][0-9]{2}:[0-9]{2}(:[0-9]{2})?([.][0-9]+)?([Zz]|[+-][0-9]{2}(:?[0-9]{2})?)$',
                    'ddmmyyyy'       => '^[0-9]{2}/[0-9]{2}/[0-9]{4}$',
                    default          => null
                };
                if ($regex) {
                    $sampleSql = sprintf(
                        "SELECT DISTINCT %s AS v FROM `%s`
                         WHERE %s REGEXP %s
                         AND %s IS NOT NULL AND %s <> ''
                         LIMIT %d",
                        $this->col($col, $dtype),
                        $this->qt($table),
                        $this->col($col, $dtype),
                        $this->q($regex),
                        $this->col($col, $dtype),
                        $this->col($col, $dtype),
                        $previewLimit
                    );
                    try {
                        $vals = array_column($pdo->query($sampleSql)->fetchAll(PDO::FETCH_ASSOC), 'v');
                        if ($vals) {
                            $samples = $vals;
                        }
                    } catch (\Throwable $e) {
                        // ignore sample errors
                    }
                }
            }

            $summaryText = sprintf(
                'total=%d; null_or_empty=%d; unix10=%d; unix13=%d; mysql_dt=%d; mysql_d=%d; iso=%d; ddmmyyyy=%d',
                $total,
                (int)($agg['null_or_empty'] ?? 0),
                (int)($agg['unix10'] ?? 0),
                (int)($agg['unix13'] ?? 0),
                (int)($agg['mysql_datetime'] ?? 0),
                (int)($agg['mysql_date'] ?? 0),
                (int)($agg['iso8601'] ?? 0),
                (int)($agg['ddmmyyyy'] ?? 0)
            );

            $target = strtoupper($dtype) . " {$classified}";
            if ($samples) {
                $recommend .= '; samples: ' . implode(' | ', array_map('strval', $samples));
            }

            $results[] = new Log(
                self::getName(),
                $table,
                $col,
                $summaryText,
                $recommend
            );
        }

        // Emit a one-line schema-wide summary to the console
        $output->writeln(sprintf(
            'Summary → native=%d, zero_date=%d, unix_s=%d, unix_ms=%d, mysql_dt=%d, mysql_d=%d, iso8601=%d, ddmmyyyy=%d, other=%d',
            $summary['native_datetime'],
            $summary['zero_date'],
            $summary['unix_seconds'],
            $summary['unix_millis'],
            $summary['mysql_datetime'],
            $summary['mysql_date'],
            $summary['iso8601'],
            $summary['ddmmyyyy'],
            $summary['other']
        ));

        return $results;
    }

    private function q(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }

    private function col(string $name, string $dtype): string
    {
        $q = '`' . $this->qt($name) . '`';
        $d = strtolower($dtype);
        // Cast numerics to char so REGEXP works
        if (in_array($d, ['int','bigint','decimal','double','float'], true)) {
            return "CAST($q AS CHAR)";
        }
        return $q;
    }
}
