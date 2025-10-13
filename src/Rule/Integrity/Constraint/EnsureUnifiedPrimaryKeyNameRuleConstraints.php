<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;

final readonly class EnsureUnifiedPrimaryKeyNameRuleConstraints implements RuleConstraintInterface
{
    /**
     * @param string[] $onlyTables
     * @param string[] $onlyTableLike
     * @param string[] $skipTables
     * @param string[] $skipTableLike
     */
    public function __construct(
        public array  $onlyTables = [],
        public array  $onlyTableLike = [],
        public array  $skipTables = [],
        public array  $skipTableLike = ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'],
        public string $targetName = 'id',
        public bool   $rebuildChildFks = false,
        public bool   $debug = false,
    ) {}

    public function toContext(): array
    {
        return [
            'only_tables'       => array_values($this->onlyTables),
            'only_table_like'   => array_values($this->onlyTableLike),
            'skip_tables'       => array_values($this->skipTables),
            'skip_table_like'   => array_values($this->skipTableLike),
            'target_name'       => $this->targetName,
            'rebuild_child_fks' => $this->rebuildChildFks,
            'debug'             => $this->debug,
        ];
    }
}
