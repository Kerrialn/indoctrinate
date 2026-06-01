<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Impact;

final class SqlChangeParser
{
    /**
     * Parse a list of SQL statements and return structured change descriptors.
     * One ALTER TABLE may contain multiple operations — each becomes its own entry.
     *
     * @param list<string> $statements
     * @return list<array{type: string, table: string, column: string, newColumn: string|null, dataType: string|null, severity: string, sql: string}>
     */
    public function parse(array $statements): array
    {
        $changes = [];

        foreach ($statements as $sql) {
            $trimmed = trim($sql);

            if (! preg_match('~^ALTER\s+TABLE\s+`?(\w+)`?~i', $trimmed, $tableMatch)) {
                continue;
            }

            $table = $tableMatch[1];

            // CHANGE [COLUMN] `old` `new` type — rename (possibly with type change)
            preg_match_all('~CHANGE\s+(?:COLUMN\s+)?`?(\w+)`?\s+`?(\w+)`?\s+(\w+)~i', $trimmed, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $isRename = strtolower($m[1]) !== strtolower($m[2]);
                $changes[] = [
                    'type' => $isRename ? 'rename_column' : 'modify_column',
                    'table' => $table,
                    'column' => $m[1],
                    'newColumn' => $isRename ? $m[2] : null,
                    'dataType' => $m[3],
                    'severity' => $isRename ? 'high' : 'medium',
                    'sql' => $trimmed,
                ];
            }

            // MODIFY [COLUMN] `col` type — in-place type change
            preg_match_all('~MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?\s+(\w+)~i', $trimmed, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $changes[] = [
                    'type' => 'modify_column',
                    'table' => $table,
                    'column' => $m[1],
                    'newColumn' => null,
                    'dataType' => $m[2],
                    'severity' => 'medium',
                    'sql' => $trimmed,
                ];
            }

            // ADD COLUMN `col` type — new column (no existing references expected)
            preg_match_all('~ADD\s+COLUMN\s+`?(\w+)`?\s+(\w+)~i', $trimmed, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $changes[] = [
                    'type' => 'add_column',
                    'table' => $table,
                    'column' => $m[1],
                    'newColumn' => null,
                    'dataType' => $m[2],
                    'severity' => 'low',
                    'sql' => $trimmed,
                ];
            }

            // DROP COLUMN `col`
            preg_match_all('~DROP\s+COLUMN\s+`?(\w+)`?~i', $trimmed, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $changes[] = [
                    'type' => 'drop_column',
                    'table' => $table,
                    'column' => $m[1],
                    'newColumn' => null,
                    'dataType' => null,
                    'severity' => 'high',
                    'sql' => $trimmed,
                ];
            }
        }

        return $changes;
    }
}
