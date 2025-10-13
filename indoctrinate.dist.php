<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Discovery\ClassifyDateStorageAcrossSchemaRule;
use Indoctrinate\Rule\Integrity\Constraint\EnsureAutoIncrementPrimaryKeyRuleConstraints;
use Indoctrinate\Rule\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use Indoctrinate\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\Integrity\EnsureTransactionalEnginesRule;
use Indoctrinate\Rule\Integrity\MissingForeignKeyRowsRule;
use Indoctrinate\Rule\Normalization\NormalizeIntColumnsRule;
use Indoctrinate\Rule\Normalization\NormalizeTinyint4ColumnsRule;
use Indoctrinate\Rule\Validation\DetectOrphanedChildRowsRule;

return static function (IndoctrinateConfig $config): void {

    $config->setConnectionCredentials(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 4406,
        dbname: 'db_name',
        user: 'user',
        password: 'password',
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