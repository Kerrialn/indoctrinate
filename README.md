# Indoctrinate

A rule-based CLI tool that audits and fixes MySQL schema issues — enforcing consistent charsets and collations, adding missing indexes, standardising primary keys, and more. Run rules individually or as curated sets, dry-run to preview changes before applying them, and configure each rule to match your schema conventions.

Built for teams migrating legacy databases to [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html), but useful for any MySQL project that needs a healthier schema.

## Installation

`composer require kerrialn/indoctrinate --dev`

## Configuration

Create `indoctrinate.php` in the root of your project:

```php
<?php

use Indoctrinate\Config\IndoctrinateConfig;

return static function (IndoctrinateConfig $config): void {
    $config->connection(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 3306,
        dbname: 'your_database',
        user: 'your_user',
        password: 'your_password',
    );

    // Register individual rules, optionally with a constraint to configure them:
    $config->rules([
        EnsureAutoIncrementPrimaryKeyRule::class => new EnsureAutoIncrementPrimaryKeyRuleConstraints(
            skipTableLike: ['%session%', '%cache%', '%temp%', '%tmp%'],
        ),
    ]);

    // Or register a curated set of rules:
    // $config->sets([DoctrineCompatibilitySet::class]);
};
```

## Available Rules & Sets

See **[RULES.md](RULES.md)** for the full list of rules and sets, with per-rule constraint argument references.

## Usage

### analyze

Audit and fix schema issues. Dry-run by default — no changes are made unless `--fix` is passed.

```
php bin/indoctrinate analyze                    # dry-run — no changes applied (default)
php bin/indoctrinate analyze --fix              # apply all fixes
php bin/indoctrinate analyze --report           # summary table, exits non-zero if findings found
php bin/indoctrinate analyze --sql-dump         # capture planned SQL to a .sql file
php bin/indoctrinate analyze --sql-dump=out.sql
php bin/indoctrinate analyze --migration        # generate a Doctrine migration class
php bin/indoctrinate analyze --migration=migrations/
php bin/indoctrinate analyze --impact           # scan src/ for code that will break
php bin/indoctrinate analyze --impact=app/src   # scan a custom directory
```

| Option | Description |
|--------|-------------|
| `--fix` | Apply fixes (default is dry-run) |
| `--report` | Print a findings summary table; exits non-zero if any found |
| `--sql-dump[=file]` | Write planned SQL to a file (default: `indoctrinate-<timestamp>.sql`) |
| `--migration[=dir]` | Write a Doctrine migration class (default dir: `migrations/`) |
| `--impact[=dir]` | Scan PHP source for code references that will break (default: `src/`). Reports column renames, drops, and type changes by severity, with file path and line number. |
| `--log=<dir>` | Write a timestamped log file to the given directory |
| `--prod` | Prod mode — override connection from `indoctrinate.php` via `--dsn` or `--db-*` flags |
| `--dsn=<dsn>` | Connection DSN, e.g. `mysql://user:pass@host:3306/db` |

### entities

Generate Doctrine entity classes directly from the database schema. Reads the connection from `indoctrinate.php`. Existing files are never overwritten — run again after deleting a file to regenerate it.

The mapping style (attributes vs. annotations) is auto-detected from the PHP version running the command, or can be forced with a flag.

```
php bin/indoctrinate entities
php bin/indoctrinate entities --output=src/Entity --namespace="App\Entity"
php bin/indoctrinate entities --table=users --table=orders   # only these tables
php bin/indoctrinate entities --skip-table="%_log"           # skip tables matching pattern
php bin/indoctrinate entities --remove-naming-prefix=app     # "app_users" → "Users"
php bin/indoctrinate entities --annotations                  # force @ORM\ annotations
php bin/indoctrinate entities --attributes                   # force #[ORM\] attributes
```

| Option | Description |
|--------|-------------|
| `--output=<dir>` | Directory to write entity files into (default: `src/Entity`) |
| `--namespace=<ns>` | PHP namespace for generated classes (default: `App\Entity`) |
| `--table=<name>` | Only generate for this table; repeatable |
| `--skip-table=<pattern>` | Skip tables matching a SQL `LIKE` pattern; repeatable |
| `--remove-naming-prefix=<prefix>` | Strip a prefix from table names when deriving class names (e.g. `app` turns `app_users` into `Users`) |
| `--annotations` | Force Doctrine `@ORM\` annotations (PHP 7 style) |
| `--attributes` | Force PHP 8 `#[ORM\]` attributes |
