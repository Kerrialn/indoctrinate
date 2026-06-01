<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service\Impact;

use Indoctrinate\Service\Impact\CodeReferenceScanner;
use PHPUnit\Framework\TestCase;

final class CodeReferenceScannerTest extends TestCase
{
    private CodeReferenceScanner $scanner;

    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->scanner = new CodeReferenceScanner();
        $this->fixtureDir = sys_get_temp_dir() . '/indoctrinate_scanner_test_' . uniqid();
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function writeFixture(string $filename, string $content): void
    {
        file_put_contents($this->fixtureDir . '/' . $filename, $content);
    }

    /** @return array<string, mixed> */
    private function change(string $type, string $table, string $column, string $severity = 'medium', ?string $newColumn = null): array
    {
        return [
            'type' => $type,
            'table' => $table,
            'column' => $column,
            'newColumn' => $newColumn,
            'dataType' => 'VARCHAR',
            'severity' => $severity,
            'sql' => '',
        ];
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testReturnsZeroFilesForNonExistentDirectory(): void
    {
        $result = $this->scanner->scan('/tmp/does_not_exist_' . uniqid(), []);
        self::assertSame(0, $result['filesScanned']);
        self::assertSame([], $result['findings']);
    }

    public function testReturnsEmptyFindingsWhenNoChanges(): void
    {
        $this->writeFixture('User.php', '<?php class User { private string $email; }');

        $result = $this->scanner->scan($this->fixtureDir, []);
        self::assertSame(1, $result['filesScanned']);
        self::assertSame([], $result['findings']);
    }

    public function testFindsSnakeCaseColumnInStringLiteral(): void
    {
        $this->writeFixture('UserRepository.php', <<<'PHP'
            <?php
            class UserRepository {
                public function findActive(): array {
                    return $this->createQueryBuilder('u')
                        ->andWhere("u.created_at > :date")
                        ->getQuery()->getResult();
                }
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('modify_column', 'users', 'created_at'),
        ]);

        self::assertCount(1, $result['findings']);
        self::assertNotEmpty($result['findings'][0]['references']);
    }

    public function testFindsCamelCasePropertyAccess(): void
    {
        $this->writeFixture('UserService.php', <<<'PHP'
            <?php
            class UserService {
                public function getDate(User $user): \DateTimeInterface {
                    return $user->getCreatedAt();
                }
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('modify_column', 'users', 'created_at'),
        ]);

        $refs = $result['findings'][0]['references'];
        self::assertNotEmpty($refs);
        self::assertStringContainsString('getCreatedAt', $refs[0]['content']);
    }

    public function testFindsDoctrineAnnotationLines(): void
    {
        $this->writeFixture('User.php', <<<'PHP'
            <?php
            use Doctrine\ORM\Mapping as ORM;
            /**
             * @ORM\Entity
             */
            class User {
                /**
                 * @ORM\Column(name="created_at", type="datetime")
                 */
                private \DateTimeInterface $createdAt;
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('modify_column', 'users', 'created_at'),
        ]);

        $contents = array_column($result['findings'][0]['references'], 'content');
        $annotationLines = array_filter($contents, fn ($c) => str_contains($c, '@ORM\\Column'));
        self::assertNotEmpty($annotationLines, 'Expected @ORM\\Column annotation line to be found');
    }

    public function testFindsPhp8AttributeLines(): void
    {
        $this->writeFixture('Product.php', <<<'PHP'
            <?php
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity]
            class Product {
                #[ORM\Column(name: 'created_at', type: 'datetime')]
                private \DateTimeInterface $createdAt;
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('modify_column', 'products', 'created_at'),
        ]);

        $contents = array_column($result['findings'][0]['references'], 'content');
        $attrLines = array_filter($contents, fn ($c) => str_contains($c, '#[ORM\\Column'));
        self::assertNotEmpty($attrLines, 'Expected #[ORM\\Column] attribute line to be found');
    }

    public function testFindsByArrayKeyInFindByCall(): void
    {
        $this->writeFixture('OrderRepository.php', <<<'PHP'
            <?php
            class OrderRepository {
                public function findByUser(int $userId): array {
                    return $this->findBy(['user_id' => $userId]);
                }
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('rename_column', 'orders', 'user_id', 'high', 'userId'),
        ]);

        self::assertNotEmpty($result['findings'][0]['references']);
    }

    public function testSkipsSingleLineComments(): void
    {
        // The first line has order_id inside a // comment — should be skipped.
        // The second line has order_id in real code — should be found.
        $this->writeFixture('Legacy.php', <<<'PHP'
            <?php
            class Legacy {
                // private string $order_id; // removed
                private string $order_id;
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('drop_column', 'orders', 'order_id', 'high'),
        ]);

        $refs = $result['findings'][0]['references'];

        // Only the non-comment line should appear
        self::assertCount(1, $refs);
        self::assertStringContainsString('private string $order_id', $refs[0]['content']);
    }

    public function testReturnsNoReferencesWhenTermAbsent(): void
    {
        $this->writeFixture('User.php', <<<'PHP'
            <?php
            class User {
                private string $email;
                public function getEmail(): string { return $this->email; }
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('drop_column', 'users', 'phone_number', 'high'),
        ]);

        self::assertEmpty($result['findings'][0]['references']);
    }

    public function testCountsScannedFilesCorrectly(): void
    {
        $this->writeFixture('A.php', '<?php class A {}');
        $this->writeFixture('B.php', '<?php class B {}');
        $this->writeFixture('C.php', '<?php class C {}');

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('modify_column', 'x', 'col'),
        ]);

        self::assertSame(3, $result['filesScanned']);
    }

    public function testScansSubdirectories(): void
    {
        mkdir($this->fixtureDir . '/Entity', 0755, true);
        $this->writeFixture('Entity/Order.php', <<<'PHP'
            <?php
            class Order {
                private string $order_id;
            }
            PHP);

        $result = $this->scanner->scan($this->fixtureDir, [
            $this->change('rename_column', 'orders', 'order_id', 'high', 'id'),
        ]);

        self::assertSame(1, $result['filesScanned']);
        self::assertNotEmpty($result['findings'][0]['references']);
    }
}
