<?php

use DbFixer\Config\DbFixerConfig;
use DbFixer\Rule\Discovery\ClassifyDateStorageAcrossSchemaRule;
use DbFixer\Rule\Integrity\Constraint\EnsureAutoIncrementPrimaryKeyRuleConstraints;
use DbFixer\Rule\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use DbFixer\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use DbFixer\Rule\Integrity\EnsureTransactionalEnginesRule;
use DbFixer\Rule\Integrity\MissingForeignKeyRowsRule;
use DbFixer\Rule\Normalization\NormalizeIntColumnsRule;
use DbFixer\Rule\Normalization\NormalizeTinyint4ColumnsRule;
use DbFixer\Rule\Validation\DetectOrphanedChildRowsRule;

return static function (DbFixerConfig $config): void {
    $config->connection(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 4406,
        dbname: 'NETWORK_SITE_DEV_DB_V1',
        user: 'root',
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