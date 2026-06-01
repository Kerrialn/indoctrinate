<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service\Impact;

use Indoctrinate\Service\Impact\SqlChangeParser;
use PHPUnit\Framework\TestCase;

final class SqlChangeParserTest extends TestCase
{
    private SqlChangeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SqlChangeParser();
    }

    public function testReturnsEmptyForNonAlterStatements(): void
    {
        $result = $this->parser->parse([
            'CREATE INDEX idx_foo ON users (foo)',
            'SELECT * FROM users',
            'UPDATE users SET name = "test"',
        ]);

        self::assertSame([], $result);
    }

    public function testDetectsRenameViaChangeColumn(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `orders` CHANGE COLUMN `order_id` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT',
        ]);

        self::assertCount(1, $result);
        self::assertSame('rename_column', $result[0]['type']);
        self::assertSame('orders', $result[0]['table']);
        self::assertSame('order_id', $result[0]['column']);
        self::assertSame('id', $result[0]['newColumn']);
        self::assertSame('high', $result[0]['severity']);
    }

    public function testDetectsModifyWhenChangeColumnKeepsSameName(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` CHANGE COLUMN `status` `status` VARCHAR(50)',
        ]);

        self::assertCount(1, $result);
        self::assertSame('modify_column', $result[0]['type']);
        self::assertSame('status', $result[0]['column']);
        self::assertNull($result[0]['newColumn']);
        self::assertSame('medium', $result[0]['severity']);
    }

    public function testDetectsModifyColumn(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` MODIFY COLUMN `created_at` DATETIME NOT NULL',
        ]);

        self::assertCount(1, $result);
        self::assertSame('modify_column', $result[0]['type']);
        self::assertSame('users', $result[0]['table']);
        self::assertSame('created_at', $result[0]['column']);
        self::assertSame('DATETIME', $result[0]['dataType']);
        self::assertSame('medium', $result[0]['severity']);
    }

    public function testDetectsModifyWithoutColumnKeyword(): void
    {
        // EnsureUnifiedPrimaryKeyNameRule emits MODIFY without the COLUMN keyword
        $result = $this->parser->parse([
            'ALTER TABLE `products` MODIFY `id` CHAR(36) NOT NULL, ADD PRIMARY KEY (`id`)',
        ]);

        $modify = array_values(array_filter($result, fn ($r) => $r['type'] === 'modify_column'));
        self::assertCount(1, $modify);
        self::assertSame('id', $modify[0]['column']);
    }

    public function testDetectsAddColumn(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` ADD COLUMN `id` INT UNSIGNED NULL',
        ]);

        self::assertCount(1, $result);
        self::assertSame('add_column', $result[0]['type']);
        self::assertSame('id', $result[0]['column']);
        self::assertSame('INT', $result[0]['dataType']);
        self::assertSame('low', $result[0]['severity']);
    }

    public function testDetectsDropColumn(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` DROP COLUMN `uuid`',
        ]);

        self::assertCount(1, $result);
        self::assertSame('drop_column', $result[0]['type']);
        self::assertSame('users', $result[0]['table']);
        self::assertSame('uuid', $result[0]['column']);
        self::assertSame('high', $result[0]['severity']);
    }

    public function testParsesMultipleOperationsInOneAlterStatement(): void
    {
        // ConvertTemporalColumnsToDatetimeRule emits multi-op ALTER statements
        $result = $this->parser->parse([
            'ALTER TABLE `events` DROP COLUMN `started_at`, CHANGE COLUMN `started_at_dt` `started_at` DATETIME',
        ]);

        $types = array_column($result, 'type');
        self::assertContains('drop_column', $types);
        self::assertContains('rename_column', $types);
    }

    public function testIgnoresEngineAndCharsetAlters(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` ENGINE = InnoDB',
            'ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);

        self::assertSame([], $result);
    }

    public function testHandlesBackticksAndNoBackticks(): void
    {
        $withBackticks = $this->parser->parse([
            'ALTER TABLE `orders` DROP COLUMN `uuid`',
        ]);
        $withoutBackticks = $this->parser->parse([
            'ALTER TABLE orders DROP COLUMN uuid',
        ]);

        self::assertSame('uuid', $withBackticks[0]['column']);
        self::assertSame('uuid', $withoutBackticks[0]['column']);
    }

    public function testDeduplicationNotRequiredEachStatementParsedOnce(): void
    {
        $result = $this->parser->parse([
            'ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(255)',
            'ALTER TABLE `orders` DROP COLUMN `legacy_id`',
        ]);

        self::assertCount(2, $result);
        self::assertSame('users', $result[0]['table']);
        self::assertSame('orders', $result[1]['table']);
    }
}
