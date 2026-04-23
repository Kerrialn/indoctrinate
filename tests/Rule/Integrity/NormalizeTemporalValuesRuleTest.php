<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Integrity;

use Indoctrinate\Rule\Integrity\NormalizeTemporalValuesRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class NormalizeTemporalValuesRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoTemporalColumns(): void
    {
        $pdo = $this->pdoWithColumns([]);

        $logs = (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testDryModeEmitsLogsForZeroDateColumn(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        // dry=true → would-do logs, no exec()
        $logs = (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        // Expects logs for: empty-string→NULL, zero-dates (two zero patterns), microseconds
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertSame('users', $log->getTable());
            $this->assertSame('created_at', $log->getColumn());
        }
    }

    public function testDryModeDoesNotCallExec(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $pdo->expects($this->never())->method('exec');

        (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);
    }

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'temp_data', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        // default skip_table_like includes %temp%
        $logs = (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testOnlyNonNullableColumnDoesNotGetNullEmptyStringFix(): void
    {
        $pdo = $this->pdoWithColumns([
            // NOT NULL datetime: only zero-date and microsecond fixes apply, not empty-string→NULL
            ['TABLE_NAME' => 'events', 'COLUMN_NAME' => 'starts_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $logs = (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        foreach ($logs as $log) {
            $this->assertStringNotContainsString('empty-string → NULL', $log->getTo());
        }
    }

    public function testDateTypeColumnProducesCorrectPatterns(): void
    {
        $pdo = $this->pdoWithColumns([
            ['TABLE_NAME' => 'events', 'COLUMN_NAME' => 'event_date', 'DATA_TYPE' => 'date', 'COLUMN_TYPE' => 'date', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
        ]);

        $logs = (new NormalizeTemporalValuesRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        // DATE type should not produce a microsecond-strip log
        $targets = array_map(fn($l) => $l->getTo(), $logs);
        $hasMicroseconds = array_filter($targets, fn($t) => strpos($t, 'microseconds') !== false);
        $this->assertCount(0, $hasMicroseconds);
    }

    private function pdoWithColumns(array $columns): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($columns);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }
}
