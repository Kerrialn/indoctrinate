<?php

namespace Indoctrinate\Rule\Contract;

use Indoctrinate\Log\Log;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

interface RuleInterface
{
    public static function getName(): string;

    public static function getDescription(): string;

    public static function getCategory(): string;

    public static function getDriver(): string;

    public static function isDestructive(): bool;

    public static function getConstraintClass(): ?string;

    /**
     * @return array<int, Log>
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array;
}