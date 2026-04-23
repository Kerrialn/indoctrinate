<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Validation;

use Indoctrinate\Rule\Validation\DetectOrphanedChildRowsRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class DetectOrphanedChildRowsRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoForeignKeys(): void
    {
        $fkStmt = $this->stmt([]);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($fkStmt);

        $logs = (new DetectOrphanedChildRowsRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testReturnsEmptyWhenNoOrphans(): void
    {
        $fkStmt = $this->stmt([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);
        $orphanStmt = $this->stmt([]); // no orphans

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $orphanStmt);

        $logs = (new DetectOrphanedChildRowsRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testDetectsSingleOrphanedRow(): void
    {
        $fkStmt = $this->stmt([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);
        $orphanStmt = $this->colStmt(['42']);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $orphanStmt);

        $logs = (new DetectOrphanedChildRowsRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
        $this->assertSame('user_id', $logs[0]->getColumn());
        $this->assertSame('42', $logs[0]->getFrom());
        $this->assertSame('ORPHAN (no match)', $logs[0]->getTo());
    }

    public function testDetectsMultipleOrphansAcrossMultipleForeignKeys(): void
    {
        $fkStmt = $this->stmt([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
            ['TABLE_NAME' => 'comments', 'COLUMN_NAME' => 'post_id', 'REFERENCED_TABLE_NAME' => 'posts', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);
        $ordersOrphanStmt = $this->colStmt(['5', '9']);
        $commentsOrphanStmt = $this->colStmt(['11']);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $ordersOrphanStmt, $commentsOrphanStmt);

        $logs = (new DetectOrphanedChildRowsRule())->apply($pdo, new NullOutput());

        $this->assertCount(3, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
        $this->assertSame('comments', $logs[2]->getTable());
    }

    private function stmt(array $rows): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        return $stmt;
    }

    private function colStmt(array $values): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($values);
        return $stmt;
    }
}
