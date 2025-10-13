<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Normalization\Constraint;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use InvalidArgumentException;

final readonly class SlugifyFieldRuleConstraints implements RuleConstraintInterface
{
    public function __construct(
        public string $table,
        public string $sourceField,
        public string $targetField,
        public int    $targetLength = 191,
        public bool   $lowercase = true,
        public string $separator = '-',
        public bool   $overwriteExisting = false,
        public bool   $createIndex = true,
        public bool   $unique = true,
        public int    $batchSize = 1000,
    ) {
        self::assertNonEmptyString('table', $this->table);
        self::assertNonEmptyString('sourceField', $this->sourceField);
        self::assertNonEmptyString('targetField', $this->targetField);
        self::assertNonEmptyString('separator', $this->separator);

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

    private static function assertNonEmptyString(string $name, string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("$name must be a non-empty string");
        }
    }
}
