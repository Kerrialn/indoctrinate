<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final readonly class EnsurePrimaryKeyUuidRuleConstraints implements RuleConstraintInterface
{
    /**
     * @param string[] $onlyTables
     * @param string[] $onlyTableLike
     * @param string[] $skipTables
     * @param string[] $skipTableLike
     */
    public function __construct(
        public array $onlyTables = [],
        public array $onlyTableLike = [],
        public array $skipTables = [],
        public array $skipTableLike = ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'],
        public bool  $cascade = false,   // <— NEW: perform coordinated migration across children
        public bool  $debug = false,
    ) {
        foreach ([$this->onlyTables, $this->onlyTableLike, $this->skipTables, $this->skipTableLike] as $arr) {
            foreach ($arr as $v) {
                if (!is_string($v) || $v === '') {
                    throw new InvalidArgumentException('Table lists/patterns must be non-empty strings.');
                }
            }
        }
    }

    public function toContext(): array
    {
        return [
            'only_tables'     => array_values($this->onlyTables),
            'only_table_like' => array_values($this->onlyTableLike),
            'skip_tables'     => array_values($this->skipTables),
            'skip_table_like' => array_values($this->skipTableLike),
            'cascade'         => $this->cascade,
            'debug'           => $this->debug,
        ];
    }
}
