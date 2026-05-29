<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Contract;

use PDO;

interface SchemaDiscoveryServiceInterface
{
    /**
     * @param string[] $onlyTables
     * @param string[] $skipPatterns
     * @return string[]
     */
    public function discoverTables(PDO $pdo, array $onlyTables, array $skipPatterns): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(PDO $pdo, string $table): array;

    /**
     * @return array<int, array<string, string>>
     */
    public function getForeignKeys(PDO $pdo, string $table): array;

    /**
     * @return string[]
     */
    public function getUniqueColumns(PDO $pdo, string $table): array;
}
