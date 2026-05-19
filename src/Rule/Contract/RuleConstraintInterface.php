<?php

namespace Indoctrinate\Rule\Contract;

interface RuleConstraintInterface
{
    /**
     * Convert to the context array the rule’s apply() expects.
     * @return array<string, mixed>
     */
    public function toContext(): array;
}