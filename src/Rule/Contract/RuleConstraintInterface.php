<?php

namespace DbFixer\Rule\Contract;

interface RuleConstraintInterface
{
    /** Convert to the context array the rule’s apply() expects. */
    public function toContext(): array;
}