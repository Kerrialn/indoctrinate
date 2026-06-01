<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service;

use Indoctrinate\Config\Context;
use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Service\RuleRunner;
use IndoctrinateTest\Service\Fixtures\FakeExceptionRule;
use IndoctrinateTest\Service\Fixtures\FakeFindingRule;
use IndoctrinateTest\Service\Fixtures\FakePassingRule;
use IndoctrinateTest\Service\Fixtures\FakeWrongDriverRule;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RuleRunnerTest extends TestCase
{
    private RuleRunner $runner;

    private SymfonyStyle $io;

    /**
     * @var list<string>
     */
    private array $logMessages = [];

    protected function setUp(): void
    {
        $this->runner = new RuleRunner();
        $this->io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $this->logMessages = [];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function pdo(): PDO
    {
        return $this->createMock(PDO::class);
    }

    /**
     * @param array<mixed> $rules
     * @param array<mixed> $sets
     */
    private function config(array $rules = [], array $sets = []): IndoctrinateConfig
    {
        $config = new IndoctrinateConfig();
        $config->setContext(new Context(true, false, null, ''));
        if ($rules !== []) {
            $config->rules($rules);
        }
        return $config;
    }

    private function logger(): callable
    {
        return function (string $msg): void {
            $this->logMessages[] = $msg;
        };
    }

    // ── empty config ──────────────────────────────────────────────────────────

    public function testEmptyConfigReturnsEmptyResult(): void
    {
        $result = $this->runner->run($this->pdo(), $this->io, $this->config(), 'mysql', true, false, false, $this->logger());

        self::assertSame([], $result->reportRows);
        self::assertSame([], $result->capturedSql);
    }

    // ── rules ─────────────────────────────────────────────────────────────────

    public function testPassingRuleAddsZeroCountReportRow(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakePassingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        self::assertCount(1, $result->reportRows);
        self::assertSame('fake_passing', $result->reportRows[0]['name']);
        self::assertSame(0, $result->reportRows[0]['count']);
        self::assertSame('standalone', $result->reportRows[0]['group']);
    }

    public function testFindingRuleAddsCorrectCountToReportRow(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeFindingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        self::assertCount(1, $result->reportRows);
        self::assertSame(1, $result->reportRows[0]['count']);
    }

    public function testRuleWithWrongDriverIsSkippedAndNotInReportRows(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeWrongDriverRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        self::assertSame([], $result->reportRows);
    }

    public function testExceptionRuleIsHandledGracefully(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeExceptionRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        // Exception is caught internally — no report row added, result still usable
        self::assertSame([], $result->reportRows);
    }

    public function testMultipleRulesAllRecordedInOrder(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakePassingRule::class => null,
                FakeFindingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        self::assertCount(2, $result->reportRows);
        self::assertSame('fake_passing', $result->reportRows[0]['name']);
        self::assertSame('fake_finding', $result->reportRows[1]['name']);
    }

    // ── SQL capture ───────────────────────────────────────────────────────────

    public function testSqlStatementFromLogCapturedWhenCapturingMode(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeFindingRule::class => null,
            ]),
            'mysql',
            true,
            true,
            false,  // isCapturing = true
            $this->logger()
        );

        self::assertNotEmpty($result->capturedSql);
        self::assertStringContainsString('ALTER TABLE', $result->capturedSql[0]);
    }

    public function testNonSqlLogNotCaptured(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakePassingRule::class => null,
            ]),
            'mysql',
            true,
            true,
            false,
            $this->logger()
        );

        self::assertSame([], $result->capturedSql);
    }

    public function testNoSqlCapturedWhenNotInCapturingMode(): void
    {
        // Even if there are findings with SQL, don't capture unless isCapturing=true
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeFindingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,  // isCapturing = false
            $this->logger()
        );

        self::assertSame([], $result->capturedSql);
    }

    // ── logger callable ───────────────────────────────────────────────────────

    public function testLoggerCallableIsInvokedForRuleTitle(): void
    {
        $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakePassingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            false,
            $this->logger()
        );

        $hasTitle = false;
        foreach ($this->logMessages as $msg) {
            if (str_contains($msg, 'fake_passing')) {
                $hasTitle = true;
                break;
            }
        }
        self::assertTrue($hasTitle, 'Logger should be called with the rule title');
    }

    // ── report mode ───────────────────────────────────────────────────────────

    public function testReportModeStillPopulatesReportRows(): void
    {
        $result = $this->runner->run(
            $this->pdo(),
            $this->io,
            $this->config([
                FakeFindingRule::class => null,
            ]),
            'mysql',
            true,
            false,
            true,  // isReport = true
            $this->logger()
        );

        self::assertCount(1, $result->reportRows);
        self::assertSame(1, $result->reportRows[0]['count']);
    }
}
