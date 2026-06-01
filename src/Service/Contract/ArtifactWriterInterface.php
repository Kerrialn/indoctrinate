<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Contract;

use Symfony\Component\Console\Style\SymfonyStyle;

interface ArtifactWriterInterface
{
    /**
     * Write $sql statements to a plain .sql file.
     *
     * @param list<string> $sql
     */
    public function writeSqlDump(array $sql, string $path, SymfonyStyle $io): void;

    /**
     * Write $sql statements into a Doctrine AbstractMigration class file.
     *
     * @param list<string> $sql
     */
    public function writeMigrationClass(array $sql, string $dir, SymfonyStyle $io): void;
}
