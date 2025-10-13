<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;

final class EnsureUnifiedPrimaryKeyNameRuleConstraints implements RuleConstraintInterface
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
    public string $targetName = 'id';
    /**
     * @readonly
     */
    public bool $rebuildChildFks = false;
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
    public function __construct(array  $onlyTables = [], array  $onlyTableLike = [], array  $skipTables = [], array  $skipTableLike = ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'], string $targetName = 'id', bool   $rebuildChildFks = false, bool   $debug = false)
    {
        $this->onlyTables = $onlyTables;
        $this->onlyTableLike = $onlyTableLike;
        $this->skipTables = $skipTables;
        $this->skipTableLike = $skipTableLike;
        $this->targetName = $targetName;
        $this->rebuildChildFks = $rebuildChildFks;
        $this->debug = $debug;
    }

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
