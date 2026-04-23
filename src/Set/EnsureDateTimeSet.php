<?php

namespace Indoctrinate\Set;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\Integrity\ConvertTemporalColumnsToDatetimeRule;
use Indoctrinate\Rule\Integrity\NormalizeTemporalValuesRule;
use Indoctrinate\Set\Contract\SetInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsureDateTimeSet implements SetInterface
{
    /**
     * @var array<class-string<RuleInterface>, RuleConstraintInterface>
     */
    private array $constraints = [];

    public function getName(): string
    {
        return 'ensure_datetime_set';
    }

    public function getDescription(): string
    {
        return 'Normalise legacy DATE/DATETIME/TIMESTAMP values, then convert all temporal columns to DATETIME.';
    }

    /**
     * @return array<int, class-string<RuleInterface>>
     */
    public function getRules(): array
    {
        // Order matters: clean data first, then convert types
        return [
            NormalizeTemporalValuesRule::class,
            ConvertTemporalColumnsToDatetimeRule::class,
        ];
    }

    /**
     * @param array<class-string<RuleInterface>, RuleConstraintInterface> $map
     */
    public function config(array $map): void
    {
        $this->constraints = $map;
    }

    public function execute(PDO $pdo, OutputInterface $io, array $context = []): array
    {
        $logs = [];

        foreach ($this->getRules() as $ruleClass) {
            /** @var RuleInterface $rule */
            $rule = new $ruleClass();

            $ruleCtx = $context;
            $constraint = $this->constraints[$ruleClass] ?? null;
            if ($constraint instanceof RuleConstraintInterface) {
                $ruleCtx = array_replace($ruleCtx, $constraint->toContext());
            }

            $logs = array_merge($logs, $rule->apply($pdo, $io, $ruleCtx));
        }

        return $logs;
    }
}
