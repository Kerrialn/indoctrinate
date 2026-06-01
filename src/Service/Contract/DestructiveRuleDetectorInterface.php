<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Contract;

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Log\Log;
use PDO;

interface DestructiveRuleDetectorInterface
{
    /**
     * Return metadata for every destructive rule active in $sets and $rules.
     *
     * @param array<mixed> $sets
     * @param array<mixed> $rules
     * @return list<array{name: string, description: string}>
     */
    public function collect(array $sets, array $rules, string $activeDriver): array;

    /**
     * Run all destructive rules in silent dry mode to count affected schema objects.
     *
     * @return list<Log>
     */
    public function discover(PDO $pdo, IndoctrinateConfig $config, string $activeDriver): array;
}
