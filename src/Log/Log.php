<?php

namespace DbFixer\Log;

use Nette\Utils\Strings;

final readonly class Log
{
    public function __construct(
        private string $rule,
        private string $table,
        private string $column,
        private string $from,
        private string $to,
    )
    {
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getMessage(): string
    {
        return sprintf(
            '[%s] %s.%s: %s → %s',
            Strings::replace($this->getRule(), '#_#', ' '),
            $this->getTable(),
            $this->getColumn(),
            $this->getFrom(),
            $this->getTo()
        );
    }

}