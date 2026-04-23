<?php

namespace Indoctrinate\Set\MySQL;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Rule\MySQL\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Set\Contract\SetInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class EnsurePrimaryKeyUuidSet implements SetInterface
{
    /**
     * @var array<class-string<RuleInterface>, RuleConstraintInterface>
     */
    private array $constraints = [];

    public function getName(): string
    {
        return 'primary_key_uuid_set';
    }

    public function getDescription(): string
    {
        return 'Switch primary keys to UUID (keeping data safe) and then unify the PK column name to `id`.';
    }

    /**
     * @return array<int, class-string<RuleInterface>>
     */
    public function getRules(): array
    {
        return [
            EnsurePrimaryKeyUuidRule::class,
            EnsureUnifiedPrimaryKeyNameRule::class,
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

