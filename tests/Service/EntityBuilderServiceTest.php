<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service;

use Indoctrinate\Service\EntityBuilderService;
use PHPUnit\Framework\TestCase;

final class EntityBuilderServiceTest extends TestCase
{
    private EntityBuilderService $service;

    protected function setUp(): void
    {
        $this->service = new EntityBuilderService();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function col(string $name, string $dataType = 'varchar', string $columnType = 'varchar(255)', bool $nullable = false, string $key = '', string $extra = '', ?int $length = 255): array
    {
        return [
            'COLUMN_NAME' => $name,
            'DATA_TYPE' => $dataType,
            'COLUMN_TYPE' => $columnType,
            'CHARACTER_MAXIMUM_LENGTH' => $length,
            'NUMERIC_PRECISION' => null,
            'NUMERIC_SCALE' => null,
            'IS_NULLABLE' => $nullable ? 'YES' : 'NO',
            'EXTRA' => $extra,
            'COLUMN_KEY' => $key,
        ];
    }

    /** @return array<string, mixed> */
    private function intCol(string $name, bool $autoIncrement = false, bool $primaryKey = false): array
    {
        return $this->col(
            $name,
            'int',
            'int(11)',
            false,
            $primaryKey ? 'PRI' : '',
            $autoIncrement ? 'auto_increment' : '',
            null
        );
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<string, array<string, string>> $fkMap
     * @param string[] $unique
     */
    private function build(array $columns, array $fkMap = [], array $unique = [], bool $attributes = true): string
    {
        return $this->service->buildFileContent('User', 'users', 'App\\Entity', $columns, $fkMap, $unique, $attributes);
    }

    // ── class-level output ────────────────────────────────────────────────────

    public function testPhp8AttributesEntityAnnotation(): void
    {
        $code = $this->build([$this->intCol('id', true, true)], [], [], true);

        self::assertStringContainsString('#[ORM\Entity]', $code);
        self::assertStringContainsString('#[ORM\Table', $code);
        self::assertStringContainsString("'users'", $code);
    }

    public function testPhp7AnnotationEntityAnnotation(): void
    {
        $code = $this->build([$this->intCol('id', true, true)], [], [], false);

        self::assertStringContainsString('@ORM\Entity', $code);
        self::assertStringContainsString('@ORM\Table(name="users")', $code);
        self::assertStringNotContainsString('#[ORM\Entity]', $code);
    }

    public function testNamespaceAndClassNamePresent(): void
    {
        $code = $this->build([$this->intCol('id', true, true)]);

        self::assertStringContainsString('namespace App\\Entity;', $code);
        self::assertStringContainsString('class User', $code);
    }

    public function testOrmMappingImportPresent(): void
    {
        $code = $this->build([$this->intCol('id', true, true)]);

        self::assertStringContainsString('use Doctrine\\ORM\\Mapping as ORM;', $code);
    }

    public function testDeclareStrictTypesPresent(): void
    {
        $code = $this->build([$this->intCol('id', true, true)]);

        // php-parser may render as "declare(strict_types=1)" or "declare (strict_types=1)"
        self::assertMatchesRegularExpression('/declare\s*\(strict_types=1\)/', $code);
    }

    // ── primary key ───────────────────────────────────────────────────────────

    public function testAutoIncrementPkGetsIdAndGeneratedValueAttributes(): void
    {
        $code = $this->build([$this->intCol('id', true, true)], [], [], true);

        self::assertStringContainsString('#[ORM\Id]', $code);
        self::assertStringContainsString('#[ORM\GeneratedValue', $code);
    }

    public function testAutoIncrementPkGetsAnnotations(): void
    {
        $code = $this->build([$this->intCol('id', true, true)], [], [], false);

        self::assertStringContainsString('@ORM\Id', $code);
        self::assertStringContainsString('@ORM\GeneratedValue', $code);
    }

    public function testAutoIncrementPkPropertyHasNullDefault(): void
    {
        $code = $this->build([$this->intCol('id', true, true)]);

        self::assertStringContainsString('private ?int $id = null', $code);
    }

    public function testAutoIncrementPkHasGetterButNoSetter(): void
    {
        $code = $this->build([$this->intCol('id', true, true)]);

        self::assertStringContainsString('public function getId()', $code);
        self::assertStringNotContainsString('public function setId(', $code);
    }

    // ── regular columns ───────────────────────────────────────────────────────

    public function testStringColumnHasGetterAndSetter(): void
    {
        $code = $this->build([$this->col('email')]);

        self::assertStringContainsString('public function getEmail()', $code);
        self::assertStringContainsString('public function setEmail(', $code);
    }

    public function testSnakeCaseColumnNameConvertedToCamelCase(): void
    {
        $code = $this->build([$this->col('first_name')]);

        self::assertStringContainsString('$firstName', $code);
        self::assertStringContainsString('getFirstName', $code);
        self::assertStringContainsString('setFirstName', $code);
    }

    public function testNullableColumnHasNullDefault(): void
    {
        $code = $this->build([$this->col('bio', 'text', 'text', true, '', '', null)]);

        self::assertStringContainsString('= null', $code);
    }

    public function testVarcharColumnIncludesLengthInOrm(): void
    {
        $code = $this->build([$this->col('slug', 'varchar', 'varchar(200)', false, '', '', 200)], [], [], true);

        // php-parser renders named attribute args as "length: 200" (PHP 8 named-arg style)
        self::assertStringContainsString('length: 200', $code);
    }

    public function testUniqueColumnFlaggedInOrmAttribute(): void
    {
        $code = $this->build([$this->col('email')], [], ['email'], true);

        self::assertStringContainsString('unique: true', $code);
    }

    public function testBooleanColumnUsesIsGetter(): void
    {
        $code = $this->build([$this->col('active', 'tinyint', 'tinyint(1)', false, '', '', null)]);

        self::assertStringContainsString('public function isActive()', $code);
        self::assertStringNotContainsString('public function getActive()', $code);
    }

    // ── temporal columns ─────────────────────────────────────────────────────

    public function testDatetimeColumnImportsDateTimeInterface(): void
    {
        $code = $this->build([$this->col('created_at', 'datetime', 'datetime', false, '', '', null)]);

        self::assertStringContainsString('use DateTimeInterface;', $code);
    }

    public function testDatetimeColumnPhpTypeIsDateTimeInterface(): void
    {
        $code = $this->build([$this->col('created_at', 'datetime', 'datetime', false, '', '', null)]);

        self::assertStringContainsString('DateTimeInterface $createdAt', $code);
    }

    // ── foreign keys ─────────────────────────────────────────────────────────

    public function testFkColumnBecomesRelationshipProperty(): void
    {
        // REFERENCED_TABLE_NAME 'users' → class 'Users' (PascalCase of raw table name)
        $fkMap = [
            'user_id' => [
                'COLUMN_NAME' => 'user_id',
                'REFERENCED_TABLE_NAME' => 'users',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];
        $columns = [$this->col('user_id', 'int', 'int(11)', false, '', '', null)];

        $code = $this->build($columns, $fkMap, [], true);

        self::assertStringContainsString('#[ORM\ManyToOne', $code);
        self::assertStringContainsString('private Users $user', $code);
        self::assertStringNotContainsString('private int $userId', $code);
    }

    public function testFkColumnStripsIdSuffixFromPropertyName(): void
    {
        $fkMap = [
            'author_id' => [
                'COLUMN_NAME' => 'author_id',
                'REFERENCED_TABLE_NAME' => 'authors',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];
        $columns = [$this->col('author_id', 'int', 'int(11)', false, '', '', null)];

        $code = $this->build($columns, $fkMap);

        // Property name strips "_id" suffix: author_id → $author
        self::assertStringContainsString('private Authors $author', $code);
    }

    public function testNullableFkPropertyIsNullable(): void
    {
        $fkMap = [
            'manager_id' => [
                'COLUMN_NAME' => 'manager_id',
                'REFERENCED_TABLE_NAME' => 'users',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];
        $columns = [$this->col('manager_id', 'int', 'int(11)', true, '', '', null)];

        $code = $this->build($columns, $fkMap);

        self::assertStringContainsString('private ?Users $manager', $code);
    }

    public function testFkImportForRelatedEntityAdded(): void
    {
        $fkMap = [
            'category_id' => [
                'COLUMN_NAME' => 'category_id',
                'REFERENCED_TABLE_NAME' => 'categories',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];
        $columns = [$this->col('category_id', 'int', 'int(11)', false, '', '', null)];

        $code = $this->build($columns, $fkMap);

        self::assertStringContainsString('use App\\Entity\\Categories;', $code);
    }

    // ── annotations style FK ─────────────────────────────────────────────────

    public function testFkAnnotationsStyle(): void
    {
        $fkMap = [
            'user_id' => [
                'COLUMN_NAME' => 'user_id',
                'REFERENCED_TABLE_NAME' => 'users',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];
        $columns = [$this->col('user_id', 'int', 'int(11)', false, '', '', null)];

        $code = $this->build($columns, $fkMap, [], false);

        self::assertStringContainsString('@ORM\ManyToOne(targetEntity=Users::class)', $code);
        self::assertStringContainsString('@ORM\JoinColumn(', $code);
    }
}
