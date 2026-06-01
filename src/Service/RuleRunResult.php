<?php

declare(strict_types=1);

namespace Indoctrinate\Service;

final class RuleRunResult
{
    /**
     * @var list<array{name: string, count: int, group: string}>
     */
    public array $reportRows = [];

    /**
     * @var list<string>
     */
    public array $capturedSql = [];
}
