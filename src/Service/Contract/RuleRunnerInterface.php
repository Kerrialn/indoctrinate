<?php

declare(strict_types=1);

namespace Indoctrinate\Service\Contract;

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Service\RuleRunResult;
use PDO;
use Symfony\Component\Console\Style\SymfonyStyle;

interface RuleRunnerInterface
{
    /**
     * Run all configured sets and rules against $pdo.
     * Writes per-rule progress directly to $io.
     * Use $logger to persist messages to an external log (e.g. a file handle wrapper).
     */
    public function run(
        PDO $pdo,
        SymfonyStyle $io,
        IndoctrinateConfig $config,
        string $activeDriver,
        bool $isDry,
        bool $isCapturing,
        bool $isReport,
        callable $logger
    ): RuleRunResult;
}
