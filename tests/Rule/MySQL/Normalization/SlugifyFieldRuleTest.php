<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Normalization;

use Indoctrinate\Rule\MySQL\Normalization\SlugifyFieldRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

final class SlugifyFieldRuleTest extends TestCase
{
    public function testThrowsWhenRequiredContextIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing required context key/');

        $pdo = $this->createMock(PDO::class);
        (new SlugifyFieldRule())->apply($pdo, new NullOutput(), []);
    }

    public function testThrowsWhenTableIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        $pdo = $this->createMock(PDO::class);
        (new SlugifyFieldRule())->apply($pdo, new NullOutput(), [
            'source_field' => 'name',
            'target_field' => 'slug',
        ]);
    }

    public function testThrowsWhenSourceColumnDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        // assertColumnExists returns false (column missing)
        $notFoundStmt = $this->createMock(PDOStatement::class);
        $notFoundStmt->method('execute')->willReturn(true);
        $notFoundStmt->method('fetchColumn')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($notFoundStmt);

        (new SlugifyFieldRule())->apply($pdo, new NullOutput(), [
            'table' => 'products',
            'source_field' => 'title',
            'target_field' => 'slug',
        ]);
    }

    public function testDryModeWithNewTargetColumnReturnsLogsWithoutExec(): void
    {
        $pdo = $this->buildDryModePdo(sourceExists: true, targetExists: false, pkCols: ['id'], rowCount: 10);

        $logs = (new SlugifyFieldRule())->apply($pdo, new NullOutput(), [
            'table' => 'products',
            'source_field' => 'title',
            'target_field' => 'slug',
            'dry' => true,
        ]);

        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertStringContainsString('DRY', $log->getFrom() . $log->getTo() . $log->getFrom());
        }
    }

    public function testDryModeDoesNotCallExec(): void
    {
        $pdo = $this->buildDryModePdo(sourceExists: true, targetExists: false, pkCols: ['id'], rowCount: 0);

        $pdo->expects($this->never())->method('exec');

        (new SlugifyFieldRule())->apply($pdo, new NullOutput(), [
            'table' => 'products',
            'source_field' => 'title',
            'target_field' => 'slug',
            'dry' => true,
        ]);
    }

    public function testDryModeWithExistingTargetSkipsAddColumnLog(): void
    {
        $pdo = $this->buildDryModePdo(sourceExists: true, targetExists: true, pkCols: ['id'], rowCount: 5);

        $logs = (new SlugifyFieldRule())->apply($pdo, new NullOutput(), [
            'table' => 'products',
            'source_field' => 'title',
            'target_field' => 'slug',
            'dry' => true,
        ]);

        $addColumnLogs = array_filter($logs, fn($l) => strpos($l->getFrom(), 'add column') !== false || strpos($l->getFrom(), 'added column') !== false);
        $this->assertCount(0, $addColumnLogs, 'No add-column log expected when target already exists');
    }

    /**
     * Build a PDO mock wired for a dry-run invocation.
     * prepare() calls in order: assertColumnExists(src), columnExists(dst), detectSingleColumnPk x2
     * query() calls in order: SHOW INDEX (haveUnique), SHOW INDEX (haveAny if needed), SELECT COUNT
     */
    private function buildDryModePdo(bool $sourceExists, bool $targetExists, array $pkCols, int $rowCount): PDO
    {
        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute')->willReturn(true);
        $existsStmt->method('fetchColumn')->willReturn($sourceExists ? '1' : false);

        $targetStmt = $this->createMock(PDOStatement::class);
        $targetStmt->method('execute')->willReturn(true);
        $targetStmt->method('fetchColumn')->willReturn($targetExists ? '1' : false);

        $pkStmt = $this->createMock(PDOStatement::class);
        $pkStmt->method('execute')->willReturn(true);
        $pkStmt->method('fetchAll')->willReturn($pkCols);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $existsStmt,  // assertColumnExists(src)
            $targetStmt,  // columnExists(dst)
            $pkStmt,      // detectSingleColumnPrimaryKey call 1
            $pkStmt,      // detectSingleColumnPrimaryKey call 2
        );

        // SHOW INDEX queries for hasIndex checks, then COUNT query
        $emptyIndexStmt = $this->createMock(PDOStatement::class);
        $emptyIndexStmt->method('fetchAll')->willReturn([]);

        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetchColumn')->willReturn((string) $rowCount);

        $pdo->method('query')->willReturnOnConsecutiveCalls(
            $emptyIndexStmt, // SHOW INDEX (haveUnique)
            $emptyIndexStmt, // SHOW INDEX (haveAny, if reached)
            $countStmt,      // SELECT COUNT(*)
        );

        return $pdo;
    }
}
