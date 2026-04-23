<?php

namespace Indoctrinate\Log;

use Nette\Utils\Strings;

final class Log
{
    /**
     * @readonly
     */
    private string $rule;

    /**
     * @readonly
     */
    private string $table;

    /**
     * @readonly
     */
    private string $column;

    /**
     * @readonly
     */
    private string $from;

    /**
     * @readonly
     */
    private string $to;

    public function __construct(string $rule, string $table, string $column, string $from, string $to)
    {
        $this->rule = $rule;
        $this->table = $table;
        $this->column = $column;
        $this->from = $from;
        $this->to = $to;
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