<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Normalization\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final class SlugifyFieldRuleConstraints implements RuleConstraintInterface
{
    /**
     * @readonly
     */
    public string $table;
    /**
     * @readonly
     */
    public string $sourceField;
    /**
     * @readonly
     */
    public string $targetField;
    /**
     * @readonly
     */
    public int $targetLength = 191;
    /**
     * @readonly
     */
    public bool $lowercase = true;
    /**
     * @readonly
     */
    public string $separator = '-';
    /**
     * @readonly
     */
    public bool $overwriteExisting = false;
    /**
     * @readonly
     */
    public bool $createIndex = true;
    /**
     * @readonly
     */
    public bool $unique = true;
    /**
     * @readonly
     */
    public int $batchSize = 1000;
    public function __construct(
        string $table,
        string $sourceField,
        string $targetField,
        int    $targetLength = 191,
        bool   $lowercase = true,
        string $separator = '-',
        bool   $overwriteExisting = false,
        bool   $createIndex = true,
        bool   $unique = true,
        int    $batchSize = 1000
    ) {
        $this->table = $table;
        $this->sourceField = $sourceField;
        $this->targetField = $targetField;
        $this->targetLength = $targetLength;
        $this->lowercase = $lowercase;
        $this->separator = $separator;
        $this->overwriteExisting = $overwriteExisting;
        $this->createIndex = $createIndex;
        $this->unique = $unique;
        $this->batchSize = $batchSize;
        $this->assertNonEmptyString('table', $this->table);
        $this->assertNonEmptyString('sourceField', $this->sourceField);
        $this->assertNonEmptyString('targetField', $this->targetField);
        $this->assertNonEmptyString('separator', $this->separator);

        if ($this->targetLength < 1) {
            throw new InvalidArgumentException('targetLength must be >= 1');
        }
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('batchSize must be >= 1');
        }
        // Keep separator sane
        if (preg_match('~[^\pL\pN\-_ ]~u', $this->separator)) {
            throw new InvalidArgumentException('separator contains unsupported characters');
        }
    }

    public function toContext(): array
    {
        // snake_case to match your existing convention
        return [
            'table'              => $this->table,
            'source_field'       => $this->sourceField,
            'target_field'       => $this->targetField,
            'target_length'      => $this->targetLength,
            'lowercase'          => $this->lowercase,
            'separator'          => $this->separator,
            'overwrite_existing' => $this->overwriteExisting,
            'create_index'       => $this->createIndex,
            'unique'             => $this->unique,
            'batch_size'         => $this->batchSize,
        ];
    }

    private function assertNonEmptyString(string $name, string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("$name must be a non-empty string");
        }
    }
}
