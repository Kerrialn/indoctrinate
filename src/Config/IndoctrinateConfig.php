<?php

// src/Config/IndoctrinateConfig.php
declare(strict_types=1);

namespace Indoctrinate\Config;

use Indoctrinate\Rule\Contract\RuleConstraintInterface;
use Indoctrinate\Rule\Contract\RuleInterface;
use Indoctrinate\Set\Contract\SetInterface;
use InvalidArgumentException;

final class IndoctrinateConfig
{
    private ?Context $context = null;

    private ?ConnectionCredentials $credentials = null;

    /**
     * [ SetFQCN => [ RuleFQCN => RuleConstraintInterface|null, ... ], ... ]
     * @var array<class-string<SetInterface>, array<class-string<RuleInterface>, RuleConstraintInterface|null>>
     */
    private array $sets = [];

    /**
     * Internally normalized to: [ RuleFQCN => RuleConstraintInterface|null ]
     * @var array<class-string<RuleInterface>, RuleConstraintInterface|null>
     */
    private array $rules = [];

    public function getConnectionCredentials(): ?ConnectionCredentials
    {
        return $this->credentials;
    }

    public function connection(
        string $driver,
        string $host,
        int $port,
        string $dbname,
        string $user,
        string $password
    ): self
    {
        // Cast port to string to satisfy ConnectionCredentials
        $this->credentials = new ConnectionCredentials(
            $driver,
            $host,
            $port,
            $dbname,
            $user,
            $password
        );
        return $this;
    }

    public function setConnectionCredentials(ConnectionCredentials $credentials): self
    {
        $this->credentials = $credentials;
        return $this;
    }

    public function getDsn(): string
    {
        if (! $this->credentials instanceof \Indoctrinate\Config\ConnectionCredentials) {
            throw new \RuntimeException('No connection configured.');
        }
        return sprintf(
            '%s:host=%s;port=%s;dbname=%s',
            $this->credentials->getDriver(),
            $this->credentials->getHost(),
            $this->credentials->getPort(),
            $this->credentials->getDatabase()
        );
    }

    /**
     * @param array<class-string<SetInterface>, array<class-string<RuleInterface>, RuleConstraintInterface|null>> $defs
     */
    public function sets(array $defs): self
    {
        foreach ($defs as $setClass => $ruleMap) {
            if (! class_exists($setClass) || ! is_subclass_of($setClass, SetInterface::class)) {
                throw new InvalidArgumentException("Set '{$setClass}' must implement SetInterface.");
            }
            if (! is_array($ruleMap)) {
                throw new InvalidArgumentException("Value for set '{$setClass}' must be an array of [RuleFQCN => Constraint|null].");
            }
            foreach ($ruleMap as $ruleClass => $constraint) {
                $this->assertRulePair($ruleClass, $constraint);
            }
        }
        $this->sets = $defs;
        return $this;
    }

    /**
     * Accepts either:
     *  - [[RuleFQCN => Constraint|null], ...] OR
     *  - [RuleFQCN => Constraint|null, ...]
     *
     * @param array<int, array<class-string<RuleInterface>, RuleConstraintInterface|null>>|array<class-string<RuleInterface>, RuleConstraintInterface|null> $defs
     */
    public function rules(array $defs): self
    {
        $map = $this->normalizeRuleDefs($defs);
        foreach ($map as $ruleClass => $constraint) {
            $this->assertRulePair($ruleClass, $constraint);
        }
        $this->rules = $map;
        return $this;
    }

    /**
     * @return array<class-string<SetInterface>, array<class-string<RuleInterface>, RuleConstraintInterface|null>>
     */
    public function getSets(): array
    {
        return $this->sets;
    }

    /**
     * @return array<class-string<RuleInterface>, RuleConstraintInterface|null>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @param mixed $ruleClass
     * @param mixed $constraint
     */
    private function assertRulePair($ruleClass, $constraint): void
    {
        if (! is_string($ruleClass) || ! class_exists($ruleClass) || ! is_subclass_of($ruleClass, RuleInterface::class)) {
            throw new InvalidArgumentException("Rule '{$ruleClass}' must implement RuleInterface.");
        }
        if ($constraint !== null && ! ($constraint instanceof RuleConstraintInterface)) {
            $t = get_debug_type($constraint);
            throw new InvalidArgumentException("Constraint for '{$ruleClass}' must be RuleConstraintInterface|null, got {$t}.");
        }
    }

    /**
     * @param array<int, array<class-string<RuleInterface>, RuleConstraintInterface|null>>|array<class-string<RuleInterface>, RuleConstraintInterface|null> $defs
     * @return array<class-string<RuleInterface>, RuleConstraintInterface|null>
     */
    private function normalizeRuleDefs(array $defs): array
    {
        $isList = function_exists('array_is_list')
            ? array_is_list($defs)
            : ($defs === [] || array_keys($defs) === range(0, count($defs) - 1));

        if (! $isList) {
            /** @var array<class-string<RuleInterface>, RuleConstraintInterface|null> $defs */
            return $defs;
        }

        $out = [];
        foreach ($defs as $i => $pair) {
            if (! is_array($pair) || count($pair) !== 1) {
                throw new InvalidArgumentException("rules()[{$i}] must be a single-element array like [RuleFQCN => Constraint|null].");
            }
            foreach ($pair as $ruleClass => $constraint) {
                $out[$ruleClass] = $constraint;
            }
        }
        return $out;
    }

    public function setContext(?Context $context): void
    {
        $this->context = $context;
    }

    public function getContext(): ?Context
    {
        return $this->context;
    }
}
