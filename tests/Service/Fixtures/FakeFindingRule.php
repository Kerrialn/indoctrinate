<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service\Fixtures;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

final class FakeFindingRule implements RuleInterface
{
    public static function getName(): string { return 'fake_finding'; }

    public static function getDescription(): string { return 'Fake rule — one finding with SQL'; }

    public static function getCategory(): string { return 'Test'; }

    public static function getDriver(): string { return 'mysql'; }

    public static function isDestructive(): bool { return false; }

    public static function getConstraintClass(): ?string { return null; }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        return [
            new Log(self::getName(), 'users', 'id', 'INT', 'ALTER TABLE `users` ADD COLUMN `id` INT UNSIGNED NULL'),
        ];
    }
}
