<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final class EnsurePrimaryKeyUuidRuleConstraints implements RuleConstraintInterface
{
    /**
     * @var string[]
     * @readonly
     */
    public array $onlyTables = [];

    /**
     * @var string[]
     * @readonly
     */
    public array $onlyTableLike = [];

    /**
     * @var string[]
     * @readonly
     */
    public array $skipTables = [];

    /**
     * @var string[]
     * @readonly
     */
    public array $skipTableLike = ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'];

    /**
     * @readonly
     */
    public bool $cascade = false;

    /**
     * @readonly
     */
    public bool $debug = false;

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
        array $skipTableLike = ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'],
        bool $cascade = false,
        bool $debug = false
    ) {
        $this->onlyTables = $onlyTables;
        $this->onlyTableLike = $onlyTableLike;
        $this->skipTables = $skipTables;
        $this->skipTableLike = $skipTableLike;
        $this->cascade = $cascade;
        // <— NEW: perform coordinated migration across children
        $this->debug = $debug;
        foreach ([$this->onlyTables, $this->onlyTableLike, $this->skipTables, $this->skipTableLike] as $arr) {
            foreach ($arr as $v) {
                if (! is_string($v) || $v === '') {
                    throw new InvalidArgumentException('Table lists/patterns must be non-empty strings.');
                }
            }
        }
    }

    public function toContext(): array
    {
        return [
            'only_tables' => array_values($this->onlyTables),
            'only_table_like' => array_values($this->onlyTableLike),
            'skip_tables' => array_values($this->skipTables),
            'skip_table_like' => array_values($this->skipTableLike),
            'cascade' => $this->cascade,
            'debug' => $this->debug,
        ];
    }
}
