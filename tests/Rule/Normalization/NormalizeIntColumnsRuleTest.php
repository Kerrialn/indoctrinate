<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Normalization;

use Indoctrinate\Rule\Normalization\NormalizeIntColumnsRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class NormalizeIntColumnsRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoIntColumns(): void
    {
        $pdo = $this->buildPdo(columns: [], fkRows: []);

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsIntWithDisplayWidth(): void
    {
        $pdo = $this->buildPdo(
            columns: [['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'age', 'COLUMN_TYPE' => 'int(11)', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => '', 'EXTRA' => '']],
            fkRows: []
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        // int(11) has display width → should be flagged
        $this->assertNotEmpty($logs);
        $tables = array_map(fn($l) => $l->getTable(), $logs);
        $this->assertContains('users', $tables);
    }

    public function testFlagsZerofillInt(): void
    {
        $pdo = $this->buildPdo(
            columns: [['TABLE_NAME' => 'stats', 'COLUMN_NAME' => 'score', 'COLUMN_TYPE' => 'int(10) unsigned zerofill', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => '', 'EXTRA' => '']],
            fkRows: []
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertNotEmpty($logs);
        $this->assertSame('stats', $logs[0]->getTable());
        $this->assertSame('score', $logs[0]->getColumn());
    }

    public function testSuggestsTinyintForInt1(): void
    {
        $pdo = $this->buildPdo(
            columns: [['TABLE_NAME' => 'products', 'COLUMN_NAME' => 'active', 'COLUMN_TYPE' => 'int(1)', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '0', 'COLUMN_KEY' => '', 'EXTRA' => '']],
            fkRows: []
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertNotEmpty($logs);
        $tinyintLog = array_filter($logs, fn($l) => $l->getTo() === 'TINYINT(1)');
        $this->assertNotEmpty($tinyintLog, 'Expected a log suggesting TINYINT(1)');
    }

    public function testCleanIntProducesNoLog(): void
    {
        $pdo = $this->buildPdo(
            columns: [['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'count', 'COLUMN_TYPE' => 'int', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => '', 'EXTRA' => '']],
            fkRows: []
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsSignednessMismatchInForeignKey(): void
    {
        $pdo = $this->buildPdo(
            columns: [],
            fkRows: [[
                'child_table' => 'orders',
                'child_column' => 'user_id',
                'child_column_type' => 'int',            // signed
                'parent_table' => 'users',
                'parent_column' => 'id',
                'parent_column_type' => 'int unsigned',  // unsigned
            ]]
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
        $this->assertSame('user_id', $logs[0]->getColumn());
        $this->assertSame('INT UNSIGNED', $logs[0]->getTo());
    }

    public function testNoLogWhenFkSignednessMatches(): void
    {
        $pdo = $this->buildPdo(
            columns: [],
            fkRows: [[
                'child_table' => 'orders',
                'child_column' => 'user_id',
                'child_column_type' => 'int unsigned',
                'parent_table' => 'users',
                'parent_column' => 'id',
                'parent_column_type' => 'int unsigned',
            ]]
        );

        $logs = (new NormalizeIntColumnsRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    /** @param array<int,array<string,string|null>> $columns @param array<int,array<string,string>> $fkRows */
    private function buildPdo(array $columns, array $fkRows): PDO
    {
        $colStmt = $this->createMock(PDOStatement::class);
        $colStmt->method('fetchAll')->willReturn($columns);

        $fkStmt = $this->createMock(PDOStatement::class);
        $fkStmt->method('fetchAll')->willReturn($fkRows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($colStmt, $fkStmt);

        return $pdo;
    }
}
