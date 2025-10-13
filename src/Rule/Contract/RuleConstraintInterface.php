<?php

namespace Indoctrinate\Rule\Contract;

interface RuleConstraintInterface
{
    /**
     * Convert to the context array the rule’s apply() expects.
     */
    public function toContext(): array;
}