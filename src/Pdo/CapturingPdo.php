<?php

declare(strict_types=1);

namespace Indoctrinate\Pdo;

/**
 * PDO subclass used by --sql-dump / --migration.
 * Intercepts exec() for DDL/DML and records the SQL instead of executing it.
 * SELECT queries pass through to the real connection so schema discovery works normally.
 */
final class CapturingPdo extends \PDO
{
    /**
     * @var list<string>
     */
    private array $captured = [];

    /**
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        if ($this->isWriteStatement($statement)) {
            $this->captured[] = rtrim(trim($statement), ';');
            return 0;
        }

        return parent::exec($statement);
    }

    private function isWriteStatement(string $sql): bool
    {
        $upper = ltrim(strtoupper(trim($sql)));

        foreach (['ALTER ', 'CREATE ', 'DROP ', 'RENAME ', 'INSERT ', 'UPDATE ', 'DELETE ', 'TRUNCATE '] as $kw) {
            if (strpos($upper, $kw) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getCapturedSql(): array
    {
        return $this->captured;
    }
}
