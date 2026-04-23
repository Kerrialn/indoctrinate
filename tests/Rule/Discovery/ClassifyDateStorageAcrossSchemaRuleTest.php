<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Discovery;

use Indoctrinate\Rule\Discovery\ClassifyDateStorageAcrossSchemaRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class ClassifyDateStorageAcrossSchemaRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoCandidateColumnsFound(): void
    {
        $candidateStmt = $this->createMock(PDOStatement::class);
        $candidateStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($candidateStmt);

        $logs = (new ClassifyDateStorageAcrossSchemaRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testNativeDatetimeColumnWithNoZerosProducesOkLog(): void
    {
        $candidateStmt = $this->createMock(PDOStatement::class);
        $candidateStmt->method('fetchAll')->willReturn([
            ['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES'],
        ]);

        $zeroCountStmt = $this->createMock(PDOStatement::class);
        $zeroCountStmt->method('fetchColumn')->willReturn('0');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($candidateStmt, $zeroCountStmt);

        $logs = (new ClassifyDateStorageAcrossSchemaRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('created_at', $logs[0]->getColumn());
        $this->assertStringContainsString('native DATETIME', $logs[0]->getFrom());
        $this->assertStringContainsString('OK', $logs[0]->getTo());
    }

    public function testNativeDatetimeColumnWithZeroRowsProducesWarningLog(): void
    {
        $candidateStmt = $this->createMock(PDOStatement::class);
        $candidateStmt->method('fetchAll')->willReturn([
            ['TABLE_NAME' => 'events', 'COLUMN_NAME' => 'starts_at', 'DATA_TYPE' => 'timestamp', 'COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => 'NO'],
        ]);

        $zeroCountStmt = $this->createMock(PDOStatement::class);
        $zeroCountStmt->method('fetchColumn')->willReturn('5');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($candidateStmt, $zeroCountStmt);

        $logs = (new ClassifyDateStorageAcrossSchemaRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('zero-date rows=5', $logs[0]->getFrom());
        $this->assertStringContainsString('DROP zero-date defaults', $logs[0]->getTo());
    }

    public function testVarcharColumnClassifiedAsUnixSecondsProducesConvertRecommendation(): void
    {
        $candidateStmt = $this->createMock(PDOStatement::class);
        $candidateStmt->method('fetchAll')->willReturn([
            ['TABLE_NAME' => 'sessions', 'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(20)', 'IS_NULLABLE' => 'YES'],
        ]);

        $aggStmt = $this->createMock(PDOStatement::class);
        $aggStmt->method('fetch')->willReturn([
            'total' => '100', 'null_or_empty' => '0',
            'unix10' => '100', 'unix13' => '0',
            'mysql_datetime' => '0', 'mysql_date' => '0',
            'iso8601' => '0', 'ddmmyyyy' => '0',
        ]);

        $sampleStmt = $this->createMock(PDOStatement::class);
        $sampleStmt->method('fetchAll')->willReturn([['v' => '1700000000']]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($candidateStmt, $aggStmt, $sampleStmt);

        $logs = (new ClassifyDateStorageAcrossSchemaRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('FROM_UNIXTIME', $logs[0]->getTo());
    }

    public function testVarcharColumnClassifiedAsMysqlDatetimeStringProducesTypeChangeRecommendation(): void
    {
        $candidateStmt = $this->createMock(PDOStatement::class);
        $candidateStmt->method('fetchAll')->willReturn([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'placed_at', 'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(30)', 'IS_NULLABLE' => 'YES'],
        ]);

        $aggStmt = $this->createMock(PDOStatement::class);
        $aggStmt->method('fetch')->willReturn([
            'total' => '50', 'null_or_empty' => '0',
            'unix10' => '0', 'unix13' => '0',
            'mysql_datetime' => '50', 'mysql_date' => '0',
            'iso8601' => '0', 'ddmmyyyy' => '0',
        ]);

        $sampleStmt = $this->createMock(PDOStatement::class);
        $sampleStmt->method('fetchAll')->willReturn([['v' => '2024-01-01 00:00:00']]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($candidateStmt, $aggStmt, $sampleStmt);

        $logs = (new ClassifyDateStorageAcrossSchemaRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('DATETIME', $logs[0]->getTo());
    }
}
