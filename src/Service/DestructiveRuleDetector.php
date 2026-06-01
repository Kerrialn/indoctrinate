<?php

declare(strict_types=1);

namespace Indoctrinate\Service;

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Service\Contract\DestructiveRuleDetectorInterface;
use Indoctrinate\Set\Contract\SetInterface;
use PDO;
use Symfony\Component\Console\Output\NullOutput;

final class DestructiveRuleDetector implements DestructiveRuleDetectorInterface
{
    /**
     * @param array<mixed> $sets
     * @param array<mixed> $rules
     * @return list<array{name: string, description: string}>
     */
    public function collect(array $sets, array $rules, string $activeDriver): array
    {
        $found = [];
        $seen = [];

        foreach (array_keys($sets) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $set = new $class();
            if (! $set instanceof SetInterface || $set->isAlwaysDry()) {
                continue;
            }
            foreach ($set->getRules() as $ruleClass) {
                if ($ruleClass::getDriver() !== $activeDriver || ! $ruleClass::isDestructive() || isset($seen[$ruleClass])) {
                    continue;
                }
                $seen[$ruleClass] = true;
                $found[] = [
                    'name' => $ruleClass::getName(),
                    'description' => $ruleClass::getDescription(),
                ];
            }
        }

        foreach ($rules as $key => $def) {
            $ruleClass = $this->resolveRuleClass($key, $def);
            if ($ruleClass === null || ! class_exists($ruleClass) || ! is_a($ruleClass, RuleInterface::class, true)) {
                continue;
            }
            if ($ruleClass::getDriver() !== $activeDriver || ! $ruleClass::isDestructive() || isset($seen[$ruleClass])) {
                continue;
            }
            $seen[$ruleClass] = true;
            $found[] = [
                'name' => $ruleClass::getName(),
                'description' => $ruleClass::getDescription(),
            ];
        }

        return $found;
    }

    /**
     * @return list<Log>
     */
    public function discover(PDO $pdo, IndoctrinateConfig $config, string $activeDriver): array
    {
        $null = new NullOutput();
        $allLogs = [];

        foreach ($config->getSets() as $class => $rulesConfiguration) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $set = new $class();
            if (! $set instanceof SetInterface || $set->isAlwaysDry()) {
                continue;
            }
            $constraints = is_array($rulesConfiguration) ? $rulesConfiguration : [];
            foreach ($set->getRules() as $ruleClass) {
                if ($ruleClass::getDriver() !== $activeDriver || ! $ruleClass::isDestructive()) {
                    continue;
                }
                $ctx = [
                    'dry' => true,
                ];
                $constraint = $constraints[$ruleClass] ?? null;
                if ($constraint instanceof RuleConstraintInterface) {
                    $ctx = array_replace($ctx, $constraint->toContext());
                }
                $allLogs = array_merge($allLogs, (new $ruleClass())->apply($pdo, $null, $ctx));
            }
        }

        foreach ($config->getRules() as $ruleClass => $constraint) {
            if (! class_exists($ruleClass) || ! is_a($ruleClass, RuleInterface::class, true)) {
                continue;
            }
            if ($ruleClass::getDriver() !== $activeDriver || ! $ruleClass::isDestructive()) {
                continue;
            }
            $ctx = [
                'dry' => true,
            ];
            if ($constraint instanceof RuleConstraintInterface) {
                $ctx = array_replace($ctx, $constraint->toContext());
            }
            $allLogs = array_merge($allLogs, (new $ruleClass())->apply($pdo, $null, $ctx));
        }

        return $allLogs;
    }

    /**
     * @param mixed $key
     * @param mixed $def
     */
    private function resolveRuleClass($key, $def): ?string
    {
        if (is_string($def) && class_exists($def)) {
            return $def;
        }
        if ($def instanceof RuleConstraintInterface && is_string($key) && class_exists($key)) {
            return $key;
        }
        if (is_array($def)) {
            if (isset($def['class']) && is_string($def['class'])) {
                return $def['class'];
            }
            if (isset($def[0]) && is_string($def[0])) {
                return $def[0];
            }
            if (is_string($key) && class_exists($key)) {
                return $key;
            }
        }
        if (is_string($key) && class_exists($key)) {
            return $key;
        }
        return null;
    }
}
