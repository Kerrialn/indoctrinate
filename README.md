# Indoctrinate

A rule-based CLI tool that audits and fixes MySQL schema issues — enforcing consistent charsets and collations, adding missing indexes, standardising primary keys, and more. Run rules individually or as curated sets, dry-run to preview changes before applying them, and configure each rule to match your schema conventions.

Built for teams migrating legacy databases to [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html), but useful for any MySQL project that needs a healthier schema.

## Installation

`composer require kerrialn/indoctrinate --dev`

## Configuration

create `config/indoctrinate.php` in the root directory of your project.

```php
<?php

return static function (DbFixerConfig $config): void {
    $config->connection(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 3306,
        dbname: 'IM_A_DATABASE_NAME,
        user: 'happy_user',
        password: '12345678',
    );

    $config->rules([
        EnsureAutoIncrementPrimaryKeyRule::class => new EnsureAutoIncrementPrimaryKeyRuleConstraints(
            false,
            false,
            [],
            ['default_ci_sessions', '%session%', '%cache%', '%temp%', '%tmp%'],
            500000,
            true,
            false
        ),
    ]);
};
```

If you want to register indiviual rule constraints, you can do so like this:

```php
$config->rules([
        EnsureAutoIncrementPrimaryKeyRule::class => new EnsureAutoIncrementPrimaryKeyRuleConstraints(
            false,
            false,
            [],
            ['default_ci_sessions', '%session%', '%cache%', '%temp%', '%tmp%'],
            500000,
            true,
            false
        ),
    ]);
```
    


## Available Rules & Sets

See **[RULES.md](RULES.md)** for the full list of available rules and sets.

To regenerate it after adding new rules:

```
php bin/indoctrinate docs
```

## Usage
`php bin/indoctrinate analyze`           — dry-run analysis (default, no changes applied)

`php bin/indoctrinate analyze --fix`     — apply all fixes

`php bin/indoctrinate analyze --report`  — summary table of findings, exits non-zero if any found

### Options
` --log=<log-dir>`