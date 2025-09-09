<?php

namespace DbFixer\Rule\Contract;

use DbFixer\Log\Log;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

interface DatabaseFixRuleInterface
{
    public static function getName(): string;

    public static function getCategory(): string;

    public static function isDestructive(): bool;

    /**
     * @return array<int, Log>
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array;
}