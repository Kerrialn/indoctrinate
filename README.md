# Indoctrinate

An automated package helps you align your database with Doctrine or simply fix database issues.

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
    


## Usage
`php bin/indoctrinate fix`

`php bin/indoctrinate fix --dry` 

### Options
` --log=<log-dir>`