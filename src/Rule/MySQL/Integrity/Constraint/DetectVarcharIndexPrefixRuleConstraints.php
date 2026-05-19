<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final class DetectVarcharIndexPrefixRuleConstraints implements RuleConstraintInterface
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

    private string $targetCharset;

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
        string $targetCharset = 'utf8mb4',
        bool $debug = false
    ) {
        if ($targetCharset === '') {
            throw new InvalidArgumentException('targetCharset must be a non-empty string.');
        }
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
        $this->targetCharset = $targetCharset;
        $this->debug = $debug;
    }

    /**
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        return [
            'only_tables'      => $this->onlyTables,
            'only_table_like'  => $this->onlyTableLike,
            'skip_tables'      => $this->skipTables,
            'skip_table_like'  => $this->skipTableLike,
            'target_charset'   => $this->targetCharset,
            'debug'            => $this->debug,
        ];
    }
}
