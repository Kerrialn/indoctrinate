<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

/**
 * For ConvertTemporalColumnsToDatetimeRule (PHP 7.4)
 */
final class ConvertTemporalColumnsToDatetimeRuleConstraints implements RuleConstraintInterface
{
    /**
     * @var string[]
     */
    private array $onlyTables;

    /**
     * @var string[]
     */
    private array $onlyTableLike;

    /**
     * @var string[]
     */
    private array $skipTables;

    /**
     * @var string[]
     */
    private array $skipTableLike;

    private bool $keepCurrentTimestamp;

    private bool $debug;

    /**
     * @param string[] $onlyTables
     * @param string[] $onlyTableLike
     * @param string[] $skipTables
     * @param string[] $skipTableLike
     */
    public function __construct(
        array $onlyTables = [],
        array $onlyTableLike = [],
        array $skipTables = [],
        array $skipTableLike = ['%tmp%', '%temp%', '%cache%'],
        bool $keepCurrentTimestamp = false,
        bool $debug = false
    ) {
        foreach ([$onlyTables, $onlyTableLike, $skipTables, $skipTableLike] as $arr) {
            foreach ($arr as $v) {
                if (! is_string($v) || $v === '') {
                    throw new InvalidArgumentException('Table lists/patterns must be non-empty strings.');
                }
            }
        }

        $this->onlyTables = array_values($onlyTables);
        $this->onlyTableLike = array_values($onlyTableLike);
        $this->skipTables = array_values($skipTables);
        $this->skipTableLike = array_values($skipTableLike);
        $this->keepCurrentTimestamp = $keepCurrentTimestamp;
        $this->debug = $debug;
    }

    public function toContext(): array
    {
        return [
            'only_tables' => $this->onlyTables,
            'only_table_like' => $this->onlyTableLike,
            'skip_tables' => $this->skipTables,
            'skip_table_like' => $this->skipTableLike,
            'keep_current_timestamp' => $this->keepCurrentTimestamp,
            'debug' => $this->debug,
        ];
    }
}
