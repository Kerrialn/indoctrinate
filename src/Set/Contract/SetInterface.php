<?php

namespace Indoctrinate\Set\Contract;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

interface SetInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return list<class-string<RuleInterface>>
     */
    public function getRules(): array;

    /**
     * Run the set (usually: run each rule in order).
     * @param array<string,mixed> $context
     * @return list<Log>
     */
    public function execute(PDO $pdo, OutputInterface $output, array $context = []): array;
}
