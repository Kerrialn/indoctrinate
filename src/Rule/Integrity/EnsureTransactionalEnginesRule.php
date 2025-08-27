<?php

namespace DbFixer\Rule\Integrity;

use DbFixer\Log\Log;
use DbFixer\Rule\Contract\DatabaseFixRuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureTransactionalEnginesRule implements DatabaseFixRuleInterface
{
    public static function getName(): string
    {
        return 'ensure_transactional_engines';
    }

    public static function getCategory(): string
    {
        return 'Integrity';
    }

    public static function isDestructive(): bool
    {
        // Changing a table engine is a heavy, blocking operation and cannot be rolled back.
        // This rule only reports what it would do.
        return true;
    }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $results = [];

        // Context flags
        $forceMemory   = (bool)($context['force_convert_memory']  ?? false);
        $forceArchive  = (bool)($context['force_convert_archive'] ?? false);
        $forceCsv      = (bool)($context['force_convert_csv']     ?? false);
        $rowFormat     = (string)($context['row_format']          ?? 'DYNAMIC');

        // Server info (useful for hints about features)
        $versionRow = $pdo->query("SELECT @@version AS v, @@version_comment AS c")->fetch(PDO::FETCH_ASSOC);
        $serverVersion = $versionRow['v'] ?? 'unknown';
        $serverComment = $versionRow['c'] ?? '';

        // All base tables with size stats
        $tables = $pdo->query("
            SELECT
                TABLE_NAME,
                ENGINE,
                ROW_FORMAT,
                TABLE_ROWS,
                DATA_LENGTH,
                INDEX_LENGTH
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $output->writeln(sprintf(
            '[%s] scanned %d tables (server: %s %s)',
            self::getName(),
            \count($tables),
            $serverVersion,
            $serverComment
        ));

        // Collect FULLTEXT and SPATIAL index presence per table (for notes)
        $fulltextByTable = $this->indexPresence($pdo, 'FULLTEXT');
        $spatialByTable  = $this->indexPresence($pdo, 'SPATIAL');

        $nonInno = 0;

        foreach ($tables as $t) {
            $table  = $t['TABLE_NAME'];
            $engine = strtoupper((string)$t['ENGINE']);
            $format = (string)$t['ROW_FORMAT'];
            $rows   = (int)$t['TABLE_ROWS'];
            $bytes  = (int)$t['DATA_LENGTH'] + (int)$t['INDEX_LENGTH'];
            $mb     = $bytes > 0 ? round($bytes / (1024 * 1024), 2) : 0.0;

            // Some system tables may have ENGINE NULL; skip those silently
            if ($engine === '' || $engine === 'INNODB') {
                continue;
            }

            $nonInno++;

            $hasFulltext = $fulltextByTable[$table] ?? false;
            $hasSpatial  = $spatialByTable[$table] ?? false;

            $notes = [];
            if ($hasFulltext) {
                $notes[] = 'table has FULLTEXT indexes';
            }
            if ($hasSpatial) {
                $notes[] = 'table has SPATIAL indexes';
            }
            $notes[] = "estimated rows: {$rows}";
            $notes[] = "approx size: {$mb} MB";

            $noteString = $notes ? ' (' . implode('; ', $notes) . ')' : '';

            // Special engines often used intentionally
            if ($engine === 'MEMORY' && !$forceMemory) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(engine)',
                    "MEMORY{$noteString}",
                    'Leave as MEMORY (ephemeral, non-transactional); set force_convert_memory=true to convert to InnoDB'
                );
                continue;
            }
            if ($engine === 'ARCHIVE' && !$forceArchive) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(engine)',
                    "ARCHIVE{$noteString}",
                    'Leave as ARCHIVE (append-only, compressed); set force_convert_archive=true to convert to InnoDB'
                );
                continue;
            }
            if ($engine === 'CSV' && !$forceCsv) {
                $results[] = new Log(
                    self::getName(),
                    $table,
                    '(engine)',
                    "CSV{$noteString}",
                    'Leave as CSV (file-backed); set force_convert_csv=true to convert to InnoDB'
                );
                continue;
            }

            // Default recommendation: convert to InnoDB with a safe row format
            $target = sprintf(
                "ALTER TABLE `%s` ENGINE=InnoDB, ROW_FORMAT=%s",
                $table,
                preg_match('/^(DYNAMIC|COMPACT|COMPRESSED)$/i', $rowFormat) ? strtoupper($rowFormat) : 'DYNAMIC'
            );

            // Add cautions for very large tables
            if ($mb >= 1024) { // >= 1 GB
                $target .= '  -- caution: large table, schedule maintenance window';
            }

            $results[] = new Log(
                self::getName(),
                $table,
                '(engine)',
                "{$engine} (row_format: {$format}){$noteString}",
                $target
            );
        }

        $output->writeln(sprintf('  • tables not using InnoDB: %d', $nonInno));

        // Optional: preview a few logs when debug is on
        if (!empty($results) && ($context['debug'] ?? false)) {
            foreach (array_slice($results, 0, 5) as $log) {
                $output->writeln('  → ' . $log->getMessage());
            }
        }

        return $results;
    }

    /**
     * Return a map table_name => bool indicating presence of a given index type.
     */
    private function indexPresence(PDO $pdo, string $indexType): array
    {
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME, 1 AS present
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND INDEX_TYPE = :type
            GROUP BY TABLE_NAME
        ");
        $stmt->execute([':type' => strtoupper($indexType)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['TABLE_NAME']] = true;
        }
        return $out;
    }
}
