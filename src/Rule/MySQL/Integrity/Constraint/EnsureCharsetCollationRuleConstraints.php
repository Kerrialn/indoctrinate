<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\MySQL\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final class EnsureCharsetCollationRuleConstraints implements RuleConstraintInterface
{
    /** @var string[] */
    private array $onlyTables;

    /** @var string[] */
    private array $onlyTableLike;

    /** @var string[] */
    private array $skipTables;

    /** @var string[] */
    private array $skipTableLike;

    private string $targetCharset;

    private string $targetCollation;

    private bool $checkColumns;

    private bool $debug;

    /**
     * @param string[] $onlyTables     Exact table names to include (empty = all).
     * @param string[] $onlyTableLike  SQL LIKE patterns to include.
     * @param string[] $skipTables     Exact table names to exclude.
     * @param string[] $skipTableLike  SQL LIKE patterns to exclude.
     */
    public function __construct(
        array $onlyTables = [],
        array $onlyTableLike = [],
        array $skipTables = [],
        array $skipTableLike = ['%tmp%', '%temp%', '%cache%'],
        string $targetCharset = 'utf8mb4',
        string $targetCollation = 'utf8mb4_unicode_ci',
        bool $checkColumns = true,
        bool $debug = false
    ) {
        if ($targetCharset === '') {
            throw new InvalidArgumentException('targetCharset must be a non-empty string.');
        }
        if ($targetCollation === '') {
            throw new InvalidArgumentException('targetCollation must be a non-empty string.');
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
        $this->targetCollation = $targetCollation;
        $this->checkColumns = $checkColumns;
        $this->debug = $debug;
    }

    /** @return array<string, mixed> */
    public function toContext(): array
    {
        return [
            'only_tables' => $this->onlyTables,
            'only_table_like' => $this->onlyTableLike,
            'skip_tables' => $this->skipTables,
            'skip_table_like' => $this->skipTableLike,
            'target_charset' => $this->targetCharset,
            'target_collation' => $this->targetCollation,
            'check_columns' => $this->checkColumns,
            'debug' => $this->debug,
        ];
    }
}
