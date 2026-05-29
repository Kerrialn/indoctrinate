<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Contract;

interface EntityBuilderServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<string, array<string, string>> $fkMap
     * @param string[] $uniqueColumns
     */
    public function buildFileContent(
        string $className,
        string $tableName,
        string $namespace,
        array $columns,
        array $fkMap,
        array $uniqueColumns,
        bool $useAttributes
    ): string;
}
