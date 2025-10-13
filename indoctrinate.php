<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use Indoctrinate\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Set\EnsurePrimaryKeyUuidSet;

return static function (IndoctrinateConfig $config): void {

    $config->connection(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 4406,
        dbname: 'NETWORK_SITE_DEV_DB_V1',
        user: 'root',
        password: '12345678',
    );

    $config->sets([
        EnsurePrimaryKeyUuidSet::class => [
            EnsurePrimaryKeyUuidRule::class => new EnsurePrimaryKeyUuidRuleConstraints(
                onlyTables: ['default_adverts'],
                onlyTableLike: [],
                skipTables: [],
                skipTableLike: [],
                cascade: true,
                debug: true
            ),
            EnsureUnifiedPrimaryKeyNameRule::class => new EnsureUnifiedPrimaryKeyNameRuleConstraints(
                onlyTables: ['default_adverts'],
                targetName: 'id',
                rebuildChildFks: true,
                debug: true
            ),
        ]
    ]);
};