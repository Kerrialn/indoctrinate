<?php

namespace DbFixer\Rule\Integrity\Constraint;


use DbFixer\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final class EnsureAutoIncrementPrimaryKeyRuleConstraints implements RuleConstraintInterface
{
    /** @var bool */
    private bool $forceOnJoinTables;
    /** @var bool */
    private bool $replaceSingleNonIntPrimary;
    /** @var string[] */
    private array $replaceSingleNonIntPrimaryAllow;
    /** @var string[] */
    private array $skipTableLike;
    /** @var int */
    private int $maxRowsToApply;
    /** @var bool */
    private bool $onlineAlters;
    /** @var bool */
    private bool $debug;

    /**
     * @param bool $forceOnJoinTables
     * @param bool $replaceSingleNonIntPrimary
     * @param string[] $replaceSingleNonIntPrimaryAllow exact table names (case-insensitive)
     * @param string[] $skipTableLike patterns (SQL LIKE style), e.g. '%session%'
     * @param int $maxRowsToApply refuse automatic apply above this estimated row count
     * @param bool $onlineAlters try ALGORITHM=INPLACE and LOCK=NONE hints
     * @param bool $debug
     */
    public function __construct(
        $forceOnJoinTables = false,
        $replaceSingleNonIntPrimary = false,
        array $replaceSingleNonIntPrimaryAllow = [],
        array $skipTableLike = ['default_ci_sessions', '%session%', '%cache%', '%temp%', '%tmp%'],
        $maxRowsToApply = 500000,
        $onlineAlters = true,
        $debug = false
    )
    {
        foreach ($replaceSingleNonIntPrimaryAllow as $t) {
            if (!is_string($t) || $t === '') {
                throw new InvalidArgumentException('replaceSingleNonIntPrimaryAllow must be non-empty strings');
            }
        }
        foreach ($skipTableLike as $p) {
            if (!is_string($p) || $p === '') {
                throw new InvalidArgumentException('skipTableLike must be non-empty strings');
            }
        }
        if (!is_int($maxRowsToApply) || $maxRowsToApply < 0) {
            throw new InvalidArgumentException('maxRowsToApply must be an integer >= 0');
        }

        $this->forceOnJoinTables = (bool)$forceOnJoinTables;
        $this->replaceSingleNonIntPrimary = (bool)$replaceSingleNonIntPrimary;
        $this->replaceSingleNonIntPrimaryAllow = array_values($replaceSingleNonIntPrimaryAllow);
        $this->skipTableLike = array_values($skipTableLike);
        $this->maxRowsToApply = (int)$maxRowsToApply;
        $this->onlineAlters = (bool)$onlineAlters;
        $this->debug = (bool)$debug;
    }

    public function toContext(): array
    {
        return [
            'force_on_join_tables' => $this->forceOnJoinTables,
            'replace_single_non_int_primary' => $this->replaceSingleNonIntPrimary,
            'replace_single_non_int_primary_allow' => $this->replaceSingleNonIntPrimaryAllow,
            'skip_table_like' => $this->skipTableLike,
            'max_rows_to_apply' => $this->maxRowsToApply,
            'online_alters' => $this->onlineAlters,
            'debug' => $this->debug,
        ];
    }
}
