<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\ConvertTemporalColumnsToDatetimeRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class ConvertTemporalColumnsToDatetimeRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoTemporalColumns(): void
    {
        $pdo = $this->pdoWithColumns([]);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testConvertsDatColumnInDryMode(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'dob', 'DATA_TYPE' => 'date', 'COLUMN_TYPE' => 'date', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        // DATE → DATETIME produces two steps: the MODIFY and the UPDATE for time portion
        $this->assertGreaterThanOrEqual(1, count($logs));
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('dob', $logs[0]->getColumn());
        $this->assertSame('dry-run', $logs[0]->getFrom());
        $this->assertStringContainsString('MODIFY DATE→DATETIME', $logs[0]->getTo());
    }

    public function testConvertsTimestampColumnInDryMode(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'posts', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'timestamp', 'COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertSame('posts', $logs[0]->getTable());
        $this->assertSame('created_at', $logs[0]->getColumn());
        $this->assertStringContainsString('TIMESTAMP→DATETIME', $logs[0]->getTo());
    }

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'cache_data', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'timestamp', 'COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        // default skip_table_like includes %cache%
        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testOnlyTablesFilterIsRespected(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'date', 'COLUMN_TYPE' => 'date', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ['TABLE_NAME' => 'posts', 'COLUMN_NAME' => 'published_at', 'DATA_TYPE' => 'date', 'COLUMN_TYPE' => 'date', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'only_tables' => ['users'],
        ]);

        foreach ($logs as $log) {
            $this->assertSame('users', $log->getTable());
        }
    }

    public function testExistingDatetimeWithZeroDefaultGetsFixedInDryMode(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'events', 'COLUMN_NAME' => 'starts_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '0000-00-00 00:00:00', 'EXTRA' => ''],
        ]);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('zero', $logs[0]->getTo());
    }

    private function pdoWithColumns(array $columns): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($columns);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }
}
