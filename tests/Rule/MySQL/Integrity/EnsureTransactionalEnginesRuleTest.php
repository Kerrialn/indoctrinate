<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\EnsureTransactionalEnginesRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureTransactionalEnginesRuleTest extends TestCase
{
    public function testSkipsInnodbTable(): void
    {
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => 'MySQL Community'],
            tables: [['TABLE_NAME' => 'users', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_ROWS' => '100', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0']],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsMyisamTable(): void
    {
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => 'MySQL Community'],
            tables: [['TABLE_NAME' => 'legacy', 'ENGINE' => 'MyISAM', 'ROW_FORMAT' => 'Fixed', 'TABLE_ROWS' => '1000', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0']],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('legacy', $logs[0]->getTable());
        $this->assertStringContainsString('ALTER TABLE `legacy` ENGINE=InnoDB', $logs[0]->getTo());
    }

    public function testMemoryTableIsSkippedByDefault(): void
    {
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => ''],
            tables: [['TABLE_NAME' => 'cache', 'ENGINE' => 'MEMORY', 'ROW_FORMAT' => 'Fixed', 'TABLE_ROWS' => '0', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0']],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Leave as MEMORY', $logs[0]->getTo());
    }

    public function testMemoryTableIsFlaggedWhenForced(): void
    {
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => ''],
            tables: [['TABLE_NAME' => 'cache', 'ENGINE' => 'MEMORY', 'ROW_FORMAT' => 'Fixed', 'TABLE_ROWS' => '0', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0']],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput(), ['force_convert_memory' => true]);

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('ENGINE=InnoDB', $logs[0]->getTo());
    }

    public function testLargeTableGetsMaintenanceCaution(): void
    {
        $oneGb = 1024 * 1024 * 1024;
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => ''],
            tables: [['TABLE_NAME' => 'big', 'ENGINE' => 'MyISAM', 'ROW_FORMAT' => 'Fixed', 'TABLE_ROWS' => '1000000', 'DATA_LENGTH' => (string) $oneGb, 'INDEX_LENGTH' => '0']],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('caution: large table', $logs[0]->getTo());
    }

    public function testReturnsNoLogsWhenAllTablesAreInnodb(): void
    {
        $pdo = $this->buildPdo(
            version: ['v' => '8.0.0', 'c' => ''],
            tables: [
                ['TABLE_NAME' => 'users', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_ROWS' => '100', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0'],
                ['TABLE_NAME' => 'orders', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_ROWS' => '200', 'DATA_LENGTH' => '0', 'INDEX_LENGTH' => '0'],
            ],
        );

        $logs = (new EnsureTransactionalEnginesRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    /** @param array<string,string> $version @param array<int,array<string,string>> $tables */
    private function buildPdo(array $version, array $tables): PDO
    {
        $versionStmt = $this->createMock(PDOStatement::class);
        $versionStmt->method('fetch')->willReturn($version);

        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        // indexPresence is called twice (FULLTEXT, SPATIAL) – return empty each time
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('execute')->willReturn(true);
        $emptyStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($versionStmt, $tableStmt);
        $pdo->method('prepare')->willReturn($emptyStmt);

        return $pdo;
    }
}
