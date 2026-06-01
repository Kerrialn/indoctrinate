<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service;

use Indoctrinate\Config\Context;
use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\MySQL\Integrity\DetectVarcharIndexPrefixRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Service\DestructiveRuleDetector;
use PHPUnit\Framework\TestCase;

final class DestructiveRuleDetectorTest extends TestCase
{
    private DestructiveRuleDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new DestructiveRuleDetector();
    }

    // ── collect ───────────────────────────────────────────────────────────────

    public function testCollectReturnsEmptyForNoRulesOrSets(): void
    {
        self::assertSame([], $this->detector->collect([], [], 'mysql'));
    }

    public function testCollectFindsDestructiveRuleFromRulesArray(): void
    {
        $result = $this->detector->collect([], [
            EnsureUnifiedPrimaryKeyNameRule::class => null,
        ], 'mysql');

        self::assertCount(1, $result);
        self::assertSame('ensure_unified_primary_key_name', $result[0]['name']);
        self::assertArrayHasKey('description', $result[0]);
    }

    public function testCollectFindsMultipleDestructiveRules(): void
    {
        $rules = [
            EnsureUnifiedPrimaryKeyNameRule::class => null,
            EnsureAutoIncrementPrimaryKeyRule::class => null,
        ];

        $result = $this->detector->collect([], $rules, 'mysql');

        self::assertCount(2, $result);
        $names = array_column($result, 'name');
        self::assertContains('ensure_unified_primary_key_name', $names);
        self::assertContains('ensure_auto_increment_primary_key', $names);
    }

    public function testCollectIgnoresNonDestructiveRules(): void
    {
        // DetectVarcharIndexPrefixRule is detection-only (isDestructive = false)
        $result = $this->detector->collect([], [
            DetectVarcharIndexPrefixRule::class => null,
        ], 'mysql');

        self::assertSame([], $result);
    }

    public function testCollectIgnoresRulesWithWrongDriver(): void
    {
        $result = $this->detector->collect([], [
            EnsureUnifiedPrimaryKeyNameRule::class => null,
        ], 'pgsql');

        self::assertSame([], $result);
    }

    public function testCollectDeduplicatesRuleAppearsInBothSetsAndRules(): void
    {
        // If the same destructive rule is registered both in a set and individually,
        // it should only appear once in the result.
        $sets = [
            \Indoctrinate\Set\MySQL\DoctrineCompatibilitySet::class => [],
        ];
        $rules = [
            EnsureUnifiedPrimaryKeyNameRule::class => null,
        ];

        $result = $this->detector->collect($sets, $rules, 'mysql');

        $names = array_column($result, 'name');
        $unique = array_unique($names);
        self::assertSame(count($names), count($unique), 'Destructive rules should not be duplicated');
    }

    public function testCollectIgnoresInvalidSetClass(): void
    {
        $result = $this->detector->collect([
            'NonExistentSet::class' => [],
        ], [], 'mysql');

        self::assertSame([], $result);
    }

    public function testCollectIgnoresInvalidRuleClass(): void
    {
        $result = $this->detector->collect([], [
            'NonExistentRule::class' => null,
        ], 'mysql');

        self::assertSame([], $result);
    }

    // ── discover ──────────────────────────────────────────────────────────────

    public function testDiscoverReturnsEmptyArrayWhenNoRulesConfigured(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $config = new IndoctrinateConfig();
        $config->setContext(new Context(true, false, null, ''));

        $result = $this->detector->discover($pdo, $config, 'mysql');

        self::assertSame([], $result);
    }
}
