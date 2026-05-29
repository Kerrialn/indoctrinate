<?php

declare(strict_types=1);

namespace Indoctrinate\Service;

use Indoctrinate\Service\Contract\SchemaDiscoveryServiceInterface;
use PDO;

final class SchemaDiscoveryService implements SchemaDiscoveryServiceInterface
{
    /**
     * @param string[] $onlyTables
     * @param string[] $skipPatterns
     * @return string[]
     */
    public function discoverTables(PDO $pdo, array $onlyTables, array $skipPatterns): array
    {
        $stmt = $pdo->query("
            SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($onlyTables !== [] && ! in_array($table, $onlyTables, true)) {
                continue;
            }
            foreach ($skipPatterns as $pattern) {
                if ($this->likeMatch((string) $table, $pattern)) {
                    continue 2;
                }
            }
            $result[] = (string) $table;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH,
                   NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE, EXTRA, COLUMN_KEY
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([
            ':table' => $table,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getForeignKeys(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
               AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
               AND tc.TABLE_NAME      = kcu.TABLE_NAME
            WHERE kcu.TABLE_SCHEMA          = DATABASE()
              AND kcu.TABLE_NAME            = :table
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
              AND tc.CONSTRAINT_TYPE        = 'FOREIGN KEY'
        ");
        $stmt->execute([
            ':table' => $table,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return string[]
     */
    public function getUniqueColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("
            SELECT DISTINCT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
              AND NON_UNIQUE = 0 AND INDEX_NAME != 'PRIMARY'
        ");
        $stmt->execute([
            ':table' => $table,
        ]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function likeMatch(string $value, string $pattern): bool
    {
        $regex = '~^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '~')) . '$~i';
        return (bool) preg_match($regex, $value);
    }
}
