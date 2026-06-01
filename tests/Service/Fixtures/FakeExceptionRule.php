<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service\Fixtures;

use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class FakeExceptionRule implements RuleInterface
{
    public static function getName(): string { return 'fake_exception'; }

    public static function getDescription(): string { return 'Fake rule — always throws'; }

    public static function getCategory(): string { return 'Test'; }

    public static function getDriver(): string { return 'mysql'; }

    public static function isDestructive(): bool { return false; }

    public static function getConstraintClass(): ?string { return null; }

    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        throw new RuntimeException('Simulated rule failure');
    }
}
