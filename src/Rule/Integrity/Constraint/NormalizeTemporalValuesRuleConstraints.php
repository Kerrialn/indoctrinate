<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Integrity\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

/**
 * For NormalizeTemporalValuesRule (PHP 7.4)
 */
final class NormalizeTemporalValuesRuleConstraints implements RuleConstraintInterface
{
    /** @var string[] */
    private array $onlyTables;
    /** @var string[] */
    private array $onlyTableLike;
    /** @var string[] */
    private array $skipTables;
    /** @var string[] */
    private array $skipTableLike;

    /** @var string 'null'|'min' */
    private string $zeroDateStrategy;
    /** @var string YYYY-MM-DD HH:MM:SS */
    private string $minDateTime;

    private bool $debug;

    /**
     * @param string[] $onlyTables
     * @param string[] $onlyTableLike
     * @param string[] $skipTables
     * @param string[] $skipTableLike
     * @param 'null'|'min' $zeroDateStrategy
     * @param string $minDateTime format 'YYYY-MM-DD HH:MM:SS'
     * @param bool $debug
     */
    public function __construct(
        array $onlyTables = [],
        array $onlyTableLike = [],
        array $skipTables = [],
        array $skipTableLike = ['%tmp%', '%temp%', '%cache%'],
        string $zeroDateStrategy = 'null',
        string $minDateTime = '1970-01-01 00:00:00',
        bool $debug = false
    ) {
        foreach ([$onlyTables, $onlyTableLike, $skipTables, $skipTableLike] as $arr) {
            foreach ($arr as $v) {
                if (!is_string($v) || $v === '') {
                    throw new InvalidArgumentException('Table lists/patterns must be non-empty strings.');
                }
            }
        }
        if ($zeroDateStrategy !== 'null' && $zeroDateStrategy !== 'min') {
            throw new InvalidArgumentException('zeroDateStrategy must be "null" or "min".');
        }
        if (!preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $minDateTime)) {
            throw new InvalidArgumentException('minDateTime must be "YYYY-MM-DD HH:MM:SS".');
        }

        $this->onlyTables       = array_values($onlyTables);
        $this->onlyTableLike    = array_values($onlyTableLike);
        $this->skipTables       = array_values($skipTables);
        $this->skipTableLike    = array_values($skipTableLike);
        $this->zeroDateStrategy = $zeroDateStrategy;
        $this->minDateTime      = $minDateTime;
        $this->debug            = $debug;
    }

    public function toContext(): array
    {
        return [
            'only_tables'        => $this->onlyTables,
            'only_table_like'    => $this->onlyTableLike,
            'skip_tables'        => $this->skipTables,
            'skip_table_like'    => $this->skipTableLike,
            'zero_date_strategy' => $this->zeroDateStrategy,
            'min_datetime'       => $this->minDateTime,
            'debug'              => $this->debug,
        ];
    }
}
