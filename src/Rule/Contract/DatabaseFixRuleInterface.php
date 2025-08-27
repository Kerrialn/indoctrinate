<?php

namespace DbFixer\Rule\Contract;

use DbFixer\Log\Log;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

interface DatabaseFixRuleInterface
{
    public static function getName(): string;
    public static function getCategory(): string;
    public static function isDestructive(): bool;

    /**
     * @param PDO $pdo
     * @param OutputInterface $output
     * @param array $context
     * @return array<int, Log>
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array;
}