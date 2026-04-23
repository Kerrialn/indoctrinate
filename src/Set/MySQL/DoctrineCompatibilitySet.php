<?php

declare(strict_types=1);

namespace Indoctrinate\Set\MySQL;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\ConvertTemporalColumnsToDatetimeRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureCharsetCollationRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureIndexOnForeignKeyRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureTransactionalEnginesRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Rule\MySQL\Integrity\MissingForeignKeyRowsRule;
use Indoctrinate\Rule\MySQL\Normalization\NormalizeIntColumnsRule;
use Indoctrinate\Set\Contract\SetInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctrineCompatibilitySet implements SetInterface
{
    /**
     * @var array<class-string<RuleInterface>, RuleConstraintInterface>
     */
    private array $constraints = [];

    public function getName(): string
    {
        return 'doctrine_compatibility';
    }

    public function getDescription(): string
    {
        return 'Read-only audit of all schema issues that prevent clean Doctrine ORM integration (always runs dry).';
    }

    /**
     * @return array<int, class-string<RuleInterface>>
     */
    public function getRules(): array
    {
        return [
            EnsureTransactionalEnginesRule::class,
            EnsureCharsetCollationRule::class,
            EnsureIndexOnForeignKeyRule::class,
            EnsureAutoIncrementPrimaryKeyRule::class,
            EnsureUnifiedPrimaryKeyNameRule::class,
            NormalizeIntColumnsRule::class,
            ConvertTemporalColumnsToDatetimeRule::class,
            MissingForeignKeyRowsRule::class,
        ];
    }

    /**
     * @param array<class-string<RuleInterface>, RuleConstraintInterface> $map
     */
    public function config(array $map): void
    {
        $this->constraints = $map;
    }

    /** @param array<string, mixed> $context */
    public function execute(PDO $pdo, OutputInterface $io, array $context = []): array
    {
        // Always dry — this set is a read-only compatibility audit
        $context['dry'] = true;

        $logs = [];

        foreach ($this->getRules() as $ruleClass) {
            /** @var RuleInterface $rule */
            $rule = new $ruleClass();

            $ruleCtx = $context;
            $constraint = $this->constraints[$ruleClass] ?? null;
            if ($constraint instanceof RuleConstraintInterface) {
                $ruleCtx = array_replace($ruleCtx, $constraint->toContext());
            }

            // Constraint config must not override dry — this set is always read-only
            $ruleCtx['dry'] = true;

            $logs = array_merge($logs, $rule->apply($pdo, $io, $ruleCtx));
        }

        return $logs;
    }
}
