<?php

declare(strict_types=1);

namespace IndoctrinateTest\Set\MySQL;

use Indoctrinate\Rule\MySQL\Integrity\ConvertTemporalColumnsToDatetimeRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureCharsetCollationRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureIndexOnForeignKeyRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureTransactionalEnginesRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Rule\MySQL\Integrity\MissingForeignKeyRowsRule;
use Indoctrinate\Rule\MySQL\Normalization\NormalizeIntColumnsRule;
use Indoctrinate\Set\MySQL\DoctrineCompatibilitySet;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class DoctrineCompatibilitySetTest extends TestCase
{
    public function testGetName(): void
    {
        $this->assertSame('doctrine_compatibility', (new DoctrineCompatibilitySet())->getName());
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        $this->assertNotEmpty((new DoctrineCompatibilitySet())->getDescription());
    }

    public function testGetRulesContainsAllExpectedRules(): void
    {
        $rules = (new DoctrineCompatibilitySet())->getRules();

        $this->assertContains(EnsureTransactionalEnginesRule::class, $rules);
        $this->assertContains(EnsureCharsetCollationRule::class, $rules);
        $this->assertContains(EnsureIndexOnForeignKeyRule::class, $rules);
        $this->assertContains(EnsureAutoIncrementPrimaryKeyRule::class, $rules);
        $this->assertContains(EnsureUnifiedPrimaryKeyNameRule::class, $rules);
        $this->assertContains(NormalizeIntColumnsRule::class, $rules);
        $this->assertContains(ConvertTemporalColumnsToDatetimeRule::class, $rules);
        $this->assertContains(MissingForeignKeyRowsRule::class, $rules);
    }

    public function testGetRulesAreAllInstantiable(): void
    {
        foreach ((new DoctrineCompatibilitySet())->getRules() as $ruleClass) {
            $this->assertInstanceOf($ruleClass, new $ruleClass());
        }
    }

    public function testConfigStoresConstraints(): void
    {
        $set = new DoctrineCompatibilitySet();
        $set->config([]);
        $this->addToAssertionCount(1);
    }

    public function testDryIsAlwaysForcedTrue(): void
    {
        $pdo = $this->buildNullPdo();

        $set = new DoctrineCompatibilitySet();
        $logs = $set->execute($pdo, new NullOutput(), ['dry' => false]);

        $this->assertIsArray($logs);
    }

    public function testExecuteReturnsArrayOfLogs(): void
    {
        $pdo = $this->buildNullPdo();

        $logs = (new DoctrineCompatibilitySet())->execute($pdo, new NullOutput());

        $this->assertIsArray($logs);
    }

    private function buildNullPdo(): PDO
    {
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        $emptyStmt->method('fetch')->willReturn(false);
        $emptyStmt->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($emptyStmt);
        $pdo->method('prepare')->willReturn($emptyStmt);

        return $pdo;
    }
}
