<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Normalization;

use Indoctrinate\Rule\MySQL\Normalization\NormalizeTinyint4ColumnsRule;
use PHPUnit\Framework\TestCase;

/**
 * NormalizeTinyint4ColumnsRule currently contains `var_dump(); exit()` — the apply()
 * method is a work-in-progress and cannot be called in tests as it would terminate the
 * test runner. Tests here cover only the static metadata interface.
 */
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

    public function testIsDestructive(): void
    {
        $this->assertTrue(NormalizeTinyint4ColumnsRule::isDestructive());
    }

    public function testGetConstraintClassReturnsNull(): void
    {
        $this->assertNull(NormalizeTinyint4ColumnsRule::getConstraintClass());
    }
}
