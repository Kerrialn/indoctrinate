<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use Indoctrinate\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Set\EnsurePrimaryKeyUuidSet;

return static function (IndoctrinateConfig $config): void {

    $config->connection(
        'mysql',
        '127.0.0.1',
        4406,
        'NETWORK_SITE_DEV_DB_V1',
        'root',
        '12345678',
    );

    $config->sets([
        EnsurePrimaryKeyUuidSet::class => [
            EnsurePrimaryKeyUuidRule::class => new EnsurePrimaryKeyUuidRuleConstraints(
                ['default_adverts'],
                [],
                [],
                [],
                true,
                true
            ),
            EnsureUnifiedPrimaryKeyNameRule::class => new EnsureUnifiedPrimaryKeyNameRuleConstraints(
                ['default_adverts'],
                [],
                [],
                ['%session%', '%sessions%', '%tmp%', '%temp%', '%cache%'],
                'id',
                true,
                true
            ),
        ]
    ]);
};