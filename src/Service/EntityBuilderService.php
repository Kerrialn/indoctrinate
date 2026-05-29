<?php

declare(strict_types=1);

namespace Indoctrinate\Service;

use Indoctrinate\Service\Contract\EntityBuilderServiceInterface;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

final class EntityBuilderService implements EntityBuilderServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<string, array<string, string>> $fkMap
     * @param string[] $uniqueColumns
     */
    public function buildFileContent(
        string $className,
        string $tableName,
        string $namespace,
        array $columns,
        array $fkMap,
        array $uniqueColumns,
        bool $useAttributes
    ): string {
        $factory = new BuilderFactory();

        $relatedImports = [];
        $needsDateTime = false;

        foreach ($columns as $col) {
            $colName = (string) $col['COLUMN_NAME'];
            if (isset($fkMap[$colName])) {
                $refClass = $this->toPascalCase($fkMap[$colName]['REFERENCED_TABLE_NAME']);
                if ($refClass !== $className) {
                    $relatedImports[$refClass] = $namespace . '\\' . $refClass;
                }
                continue;
            }
            if (in_array($this->doctrineType((string) $col['DATA_TYPE'], (string) $col['COLUMN_TYPE']), ['date', 'datetime', 'time'], true)) {
                $needsDateTime = true;
            }
        }

        $classBuilder = $factory->class($className);

        if ($useAttributes) {
            $classBuilder->addAttribute($factory->attribute('ORM\\Entity'));
            $classBuilder->addAttribute($factory->attribute('ORM\\Table', [
                'name' => $tableName,
            ]));
        } else {
            $classBuilder->setDocComment(sprintf("/**\n * @ORM\\Entity\n * @ORM\\Table(name=\"%s\")\n */", $tableName));
        }

        foreach ($columns as $col) {
            $colName = (string) $col['COLUMN_NAME'];
            $isNullable = $col['IS_NULLABLE'] === 'YES';

            if (isset($fkMap[$colName])) {
                $classBuilder->addStmt($this->buildFkProperty($factory, $colName, $fkMap[$colName], $isNullable, $useAttributes));
            } else {
                $classBuilder->addStmt($this->buildColumnProperty($factory, $col, $uniqueColumns, $useAttributes));
            }
        }

        foreach ($columns as $col) {
            $colName = (string) $col['COLUMN_NAME'];
            $isNullable = $col['IS_NULLABLE'] === 'YES';
            $isPk = $col['COLUMN_KEY'] === 'PRI';
            $isAutoIncrement = stripos((string) $col['EXTRA'], 'auto_increment') !== false;

            if (isset($fkMap[$colName])) {
                $fk = $fkMap[$colName];
                $refClass = $this->toPascalCase($fk['REFERENCED_TABLE_NAME']);
                $propName = $this->relationshipPropName($colName);
                $phpType = ($isNullable ? '?' : '') . $refClass;
                $classBuilder->addStmt($this->buildGetter($factory, $propName, $phpType, false));
                $classBuilder->addStmt($this->buildSetter($factory, $propName, $phpType));
                continue;
            }

            $dtype = $this->doctrineType((string) $col['DATA_TYPE'], (string) $col['COLUMN_TYPE']);
            $phpType = $this->phpType($dtype, ($isPk && $isAutoIncrement) || $isNullable);
            $propName = $this->toCamelCase($colName);

            $classBuilder->addStmt($this->buildGetter($factory, $propName, $phpType, $dtype === 'boolean'));
            if (! ($isPk && $isAutoIncrement)) {
                $classBuilder->addStmt($this->buildSetter($factory, $propName, $phpType));
            }
        }

        $nsBuilder = $factory->namespace($namespace);
        $nsBuilder->addStmt($factory->use('Doctrine\\ORM\\Mapping')->as('ORM'));
        foreach (array_unique($relatedImports) as $fqcn) {
            $nsBuilder->addStmt($factory->use($fqcn));
        }
        if ($needsDateTime) {
            $nsBuilder->addStmt($factory->use('DateTimeInterface'));
        }
        $nsBuilder->addStmt($classBuilder);

        $declare = new Node\Stmt\Declare_([
            new Node\Stmt\DeclareDeclare(
                new Node\Identifier('strict_types'),
                new Node\Scalar\LNumber(1)
            ),
        ]);

        $code = (new Standard([
            'shortArraySyntax' => true,
        ]))
            ->prettyPrintFile([$declare, $nsBuilder->getNode()]);

        return $this->prettify($code);
    }

    // ── Property builders ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $col
     * @param array<int, string> $uniqueColumns
     */
    private function buildColumnProperty(BuilderFactory $factory, array $col, array $uniqueColumns, bool $useAttributes): Node\Stmt\Property
    {
        $colName = (string) $col['COLUMN_NAME'];
        $isPk = $col['COLUMN_KEY'] === 'PRI';
        $isAutoIncrement = stripos((string) $col['EXTRA'], 'auto_increment') !== false;
        $isNullable = $col['IS_NULLABLE'] === 'YES';
        $isUnique = in_array($colName, $uniqueColumns, true) && ! $isPk;
        $dtype = $this->doctrineType((string) $col['DATA_TYPE'], (string) $col['COLUMN_TYPE']);
        $phpType = $this->phpType($dtype, ($isPk && $isAutoIncrement) || $isNullable);
        $propName = $this->toCamelCase($colName);

        $propBuilder = $factory->property($propName)->makePrivate()->setType($phpType);

        if ($isNullable || ($isPk && $isAutoIncrement)) {
            $propBuilder->setDefault(null);
        }

        if ($useAttributes) {
            if ($isPk) {
                $propBuilder->addAttribute($factory->attribute('ORM\\Id'));
                if ($isAutoIncrement) {
                    $propBuilder->addAttribute($factory->attribute('ORM\\GeneratedValue', [
                        'strategy' => 'AUTO',
                    ]));
                }
            }
            $propBuilder->addAttribute($factory->attribute('ORM\\Column', $this->buildAttrArgs($col, $dtype, $isNullable, $isUnique)));
        } else {
            $lines = ['/**'];
            if ($isPk) {
                $lines[] = ' * @ORM\\Id';
                if ($isAutoIncrement) {
                    $lines[] = ' * @ORM\\GeneratedValue(strategy="AUTO")';
                }
            }
            $lines[] = ' * @ORM\\Column(' . $this->buildAnnotationArgs($col, $dtype, $isNullable, $isUnique) . ')';
            $lines[] = ' */';
            $propBuilder->setDocComment(implode("\n", $lines));
        }

        return $propBuilder->getNode();
    }

    /**
     * @param array<string, string> $fk
     */
    private function buildFkProperty(BuilderFactory $factory, string $colName, array $fk, bool $isNullable, bool $useAttributes): Node\Stmt\Property
    {
        $refClass = $this->toPascalCase($fk['REFERENCED_TABLE_NAME']);
        $propName = $this->relationshipPropName($colName);
        $phpType = ($isNullable ? '?' : '') . $refClass;

        $propBuilder = $factory->property($propName)->makePrivate()->setType($phpType);

        if ($isNullable) {
            $propBuilder->setDefault(null);
        }

        if ($useAttributes) {
            $propBuilder->addAttribute($factory->attribute('ORM\\ManyToOne', [
                'targetEntity' => $factory->classConstFetch($refClass, 'class'),
            ]));

            $joinArgs = [
                'name' => $colName,
                'referencedColumnName' => $fk['REFERENCED_COLUMN_NAME'],
            ];
            if (! $isNullable) {
                $joinArgs['nullable'] = false;
            }
            $propBuilder->addAttribute($factory->attribute('ORM\\JoinColumn', $joinArgs));
        } else {
            $joinArgs = sprintf('name="%s", referencedColumnName="%s"', $colName, $fk['REFERENCED_COLUMN_NAME']);
            if (! $isNullable) {
                $joinArgs .= ', nullable=false';
            }
            $propBuilder->setDocComment(implode("\n", [
                '/**',
                " * @ORM\\ManyToOne(targetEntity={$refClass}::class)",
                " * @ORM\\JoinColumn({$joinArgs})",
                ' */',
            ]));
        }

        return $propBuilder->getNode();
    }

    private function buildGetter(BuilderFactory $factory, string $propName, string $phpType, bool $isBoolean): Node\Stmt\ClassMethod
    {
        $name = ($isBoolean ? 'is' : 'get') . ucfirst($propName);

        return $factory->method($name)
            ->makePublic()
            ->setReturnType($phpType)
            ->addStmt(new Node\Stmt\Return_(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $propName)
            ))
            ->getNode();
    }

    private function buildSetter(BuilderFactory $factory, string $propName, string $phpType): Node\Stmt\ClassMethod
    {
        return $factory->method('set' . ucfirst($propName))
            ->makePublic()
            ->addParam($factory->param($propName)->setType($phpType))
            ->setReturnType('self')
            ->addStmt(new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $propName),
                    new Node\Expr\Variable($propName)
                )
            ))
            ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
            ->getNode();
    }

    // ── Attribute / annotation arg builders ───────────────────────────────────

    /**
     * @param array<string, mixed> $col
     * @return array<string, mixed>
     */
    private function buildAttrArgs(array $col, string $dtype, bool $isNullable, bool $isUnique): array
    {
        $args = [
            'type' => $dtype,
        ];

        if ($dtype === 'string' && ! empty($col['CHARACTER_MAXIMUM_LENGTH'])) {
            $args['length'] = (int) $col['CHARACTER_MAXIMUM_LENGTH'];
        }
        if ($dtype === 'decimal' && ! empty($col['NUMERIC_PRECISION'])) {
            $args['precision'] = (int) $col['NUMERIC_PRECISION'];
            $args['scale'] = (int) ($col['NUMERIC_SCALE'] ?? 0);
        }
        if ($isNullable) {
            $args['nullable'] = true;
        }
        if ($isUnique) {
            $args['unique'] = true;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $col
     */
    private function buildAnnotationArgs(array $col, string $dtype, bool $isNullable, bool $isUnique): string
    {
        $parts = [sprintf('type="%s"', $dtype)];

        if ($dtype === 'string' && ! empty($col['CHARACTER_MAXIMUM_LENGTH'])) {
            $parts[] = 'length=' . (int) $col['CHARACTER_MAXIMUM_LENGTH'];
        }
        if ($dtype === 'decimal' && ! empty($col['NUMERIC_PRECISION'])) {
            $parts[] = 'precision=' . (int) $col['NUMERIC_PRECISION'];
            $parts[] = 'scale=' . (int) ($col['NUMERIC_SCALE'] ?? 0);
        }
        if ($isNullable) {
            $parts[] = 'nullable=true';
        }
        if ($isUnique) {
            $parts[] = 'unique=true';
        }

        return implode(', ', $parts);
    }

    // ── Type mapping ──────────────────────────────────────────────────────────

    private function doctrineType(string $dataType, string $columnType): string
    {
        $dt = strtolower($dataType);

        if ($dt === 'tinyint' && strpos(strtolower($columnType), 'tinyint(1)') !== false) {
            return 'boolean';
        }

        switch ($dt) {
            case 'tinyint':
            case 'smallint':
                return 'smallint';
            case 'mediumint':
            case 'int':
                return 'integer';
            case 'bigint':
                return 'bigint';
            case 'float':
            case 'double':
            case 'real':
                return 'float';
            case 'decimal':
            case 'numeric':
                return 'decimal';
            case 'char':
            case 'varchar':
            case 'enum':
            case 'set':
                return 'string';
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return 'text';
            case 'json':
                return 'json';
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'datetime';
            case 'time':
                return 'time';
            case 'year':
                return 'integer';
            case 'binary':
            case 'varbinary':
                return 'binary';
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return 'blob';
            default:
                return 'string';
        }
    }

    private function phpType(string $doctrineType, bool $nullable): string
    {
        $map = [
            'boolean' => 'bool',
            'smallint' => 'int',
            'integer' => 'int',
            'bigint' => 'int',
            'float' => 'float',
            'decimal' => 'string',
            'string' => 'string',
            'text' => 'string',
            'json' => 'array',
            'date' => 'DateTimeInterface',
            'datetime' => 'DateTimeInterface',
            'time' => 'DateTimeInterface',
            'binary' => 'string',
            'blob' => 'string',
        ];

        $type = $map[$doctrineType] ?? 'string';
        return $nullable ? '?' . $type : $type;
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    private function prettify(string $code): string
    {
        $code = preg_replace('/\n(    (?:private|protected|public|#\[))/', "\n\n$1", $code) ?? $code;
        $code = preg_replace('/\n{3,}/', "\n\n", $code) ?? $code;
        return $code;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function toPascalCase(string $input): string
    {
        return str_replace('_', '', ucwords($input, '_'));
    }

    private function toCamelCase(string $input): string
    {
        return lcfirst($this->toPascalCase($input));
    }

    private function relationshipPropName(string $columnName): string
    {
        $base = (string) preg_replace('/_id$/i', '', $columnName);
        return $this->toCamelCase($base !== '' && $base !== $columnName ? $base : $columnName);
    }
}
