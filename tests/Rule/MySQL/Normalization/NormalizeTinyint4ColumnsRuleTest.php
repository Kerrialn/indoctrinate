<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Normalization;

use Indoctrinate\Rule\MySQL\Normalization\NormalizeTinyint4ColumnsRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class NormalizeTinyint4ColumnsRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $this->assertSame('normalize_tinyint4_columns', NormalizeTinyint4ColumnsRule::getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty(NormalizeTinyint4ColumnsRule::getDescription());
    }

    public function testGetCategory(): void
    {
        $this->assertSame('Normalization', NormalizeTinyint4ColumnsRule::getCategory());
    }

    public function testGetDriver(): void
    {
        $this->assertSame('mysql', NormalizeTinyint4ColumnsRule::getDriver());
    }

    public function testIsNotDestructive(): void
    {
        $this->assertFalse(NormalizeTinyint4ColumnsRule::isDestructive());
    }

    public function testGetConstraintClassReturnsNull(): void
    {
        $this->assertNull(NormalizeTinyint4ColumnsRule::getConstraintClass());
    }

    public function testReturnsEmptyWhenNoColumns(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply($this->buildPdo([]), new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsTinyint4(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'status', 'COLUMN_TYPE' => 'tinyint(4)']]),
            new NullOutput()
        );

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('status', $logs[0]->getColumn());
        $this->assertSame('tinyint(4)', $logs[0]->getFrom());
        $this->assertStringContainsString('TINYINT', $logs[0]->getTo());
        $this->assertStringNotContainsString('UNSIGNED', $logs[0]->getTo());
    }

    public function testFlagsTinyint4Unsigned(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'products', 'COLUMN_NAME' => 'qty', 'COLUMN_TYPE' => 'tinyint(4) unsigned']]),
            new NullOutput()
        );

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('TINYINT UNSIGNED', $logs[0]->getTo());
    }

    public function testSkipsTinyint1(): void
    {
        // tinyint(1) is Doctrine's boolean — must not be touched
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'active', 'COLUMN_TYPE' => 'tinyint(1)']]),
            new NullOutput()
        );

        $this->assertCount(0, $logs);
    }

    public function testSkipsTinyint1Unsigned(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'flag', 'COLUMN_TYPE' => 'tinyint(1) unsigned']]),
            new NullOutput()
        );

        $this->assertCount(0, $logs);
    }

    public function testSkipsCleanTinyint(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'score', 'COLUMN_TYPE' => 'tinyint']]),
            new NullOutput()
        );

        $this->assertCount(0, $logs);
    }

    public function testSkipsCleanTinyintUnsigned(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'score', 'COLUMN_TYPE' => 'tinyint unsigned']]),
            new NullOutput()
        );

        $this->assertCount(0, $logs);
    }

    public function testOnlyFlagsColumnsWithDisplayWidth(): void
    {
        $rows = [
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'active', 'COLUMN_TYPE' => 'tinyint(1)'],   // skip
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'status', 'COLUMN_TYPE' => 'tinyint(4)'],   // flag
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'score',  'COLUMN_TYPE' => 'tinyint'],      // skip
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'level',  'COLUMN_TYPE' => 'tinyint(2)'],   // flag
        ];

        $logs = (new NormalizeTinyint4ColumnsRule())->apply($this->buildPdo($rows), new NullOutput());

        $this->assertCount(2, $logs);
        $columns = array_map(fn($l) => $l->getColumn(), $logs);
        $this->assertContains('status', $columns);
        $this->assertContains('level', $columns);
    }

    public function testLogToContainsAlterTableStatement(): void
    {
        $logs = (new NormalizeTinyint4ColumnsRule())->apply(
            $this->buildPdo([['TABLE_NAME' => 'foo', 'COLUMN_NAME' => 'bar', 'COLUMN_TYPE' => 'tinyint(4)']]),
            new NullOutput()
        );

        $this->assertStringContainsString('ALTER TABLE', $logs[0]->getTo());
        $this->assertStringContainsString('`foo`', $logs[0]->getTo());
        $this->assertStringContainsString('`bar`', $logs[0]->getTo());
    }

    /** @param array<int, array<string, string>> $rows */
    private function buildPdo(array $rows): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }
}
