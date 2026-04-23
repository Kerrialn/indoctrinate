<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Integrity;

use Indoctrinate\Rule\Integrity\MissingForeignKeyRowsRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class MissingForeignKeyRowsRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoForeignKeys(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->stmt([]));

        $logs = (new MissingForeignKeyRowsRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testReturnsEmptyWhenNoMissingParentRows(): void
    {
        $fkStmt = $this->stmt([
            ['table_name' => 'orders', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id', 'constraint_name' => 'fk_orders_user'],
        ]);
        $missingStmt = $this->colStmt([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $missingStmt);

        $logs = (new MissingForeignKeyRowsRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(0, $logs);
    }

    public function testDetectsMissingParentRowAndLogsInDryMode(): void
    {
        $fkStmt = $this->stmt([
            ['table_name' => 'orders', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id', 'constraint_name' => 'fk_orders_user'],
        ]);
        $missingStmt = $this->colStmt([['missing_id' => '99']]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $missingStmt);
        $pdo->expects($this->never())->method('prepare'); // dry=true: no stub inserts

        $logs = (new MissingForeignKeyRowsRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('id', $logs[0]->getColumn());
        $this->assertSame('MISSING ID', $logs[0]->getFrom());
        $this->assertSame('99', $logs[0]->getTo());
    }

    public function testDefaultBehaviourIsDryMode(): void
    {
        // dry defaults to true; prepare() for INSERT should never be called
        $fkStmt = $this->stmt([
            ['table_name' => 'orders', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id', 'constraint_name' => 'fk'],
        ]);
        $missingStmt = $this->colStmt([['missing_id' => '7']]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $missingStmt);
        $pdo->expects($this->never())->method('prepare');

        (new MissingForeignKeyRowsRule())->apply($pdo, new NullOutput());
    }

    public function testDetectsMultipleMissingParentsAcrossForeignKeys(): void
    {
        $fkStmt = $this->stmt([
            ['table_name' => 'orders', 'column_name' => 'user_id', 'referenced_table_name' => 'users', 'referenced_column_name' => 'id', 'constraint_name' => 'fk1'],
            ['table_name' => 'items', 'column_name' => 'order_id', 'referenced_table_name' => 'orders', 'referenced_column_name' => 'id', 'constraint_name' => 'fk2'],
        ]);
        $missingUsersStmt = $this->colStmt([['missing_id' => '1'], ['missing_id' => '2']]);
        $missingOrdersStmt = $this->colStmt([['missing_id' => '3']]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($fkStmt, $missingUsersStmt, $missingOrdersStmt);

        $logs = (new MissingForeignKeyRowsRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(3, $logs);
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
