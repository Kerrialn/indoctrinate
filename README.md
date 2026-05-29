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

```
php bin/indoctrinate analyze                    # dry-run analysis — no changes applied (default)
php bin/indoctrinate analyze --fix              # apply all fixes
php bin/indoctrinate analyze --report           # summary table, exits non-zero if findings found
php bin/indoctrinate analyze --sql-dump         # capture planned SQL to a .sql file
php bin/indoctrinate analyze --sql-dump=out.sql
php bin/indoctrinate analyze --migration        # generate a Doctrine migration class
php bin/indoctrinate analyze --migration=migrations/
```

### Options

| Option | Description |
|--------|-------------|
| `--fix` | Apply fixes (default is dry-run) |
| `--report` | Print a findings summary table; exits non-zero if any found |
| `--sql-dump[=file]` | Write planned SQL to a file (default: `indoctrinate-<timestamp>.sql`) |
| `--migration[=dir]` | Write a Doctrine migration class (default dir: `migrations/`) |
| `--log=<dir>` | Write a timestamped log file to the given directory |
| `--prod` | Prod mode — override connection from `indoctrinate.php` via `--dsn` or `--db-*` flags |
| `--dsn=<dsn>` | Connection DSN, e.g. `mysql://user:pass@host:3306/db` |
