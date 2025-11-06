<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Integrity\Constraint\ConvertTemporalColumnsToDatetimeRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\NormalizeTemporalValuesRuleConstraints;
use Indoctrinate\Rule\Integrity\ConvertTemporalColumnsToDatetimeRule;
use Indoctrinate\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Rule\Integrity\NormalizeTemporalValuesRule;
use Indoctrinate\Rule\Normalization\Constraint\SlugifyFieldRuleConstraints;
use Indoctrinate\Rule\Normalization\SlugifyFieldRule;
use Indoctrinate\Set\EnsureDateTimeSet;
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
        EnsureDateTimeSet::class => [
            NormalizeTemporalValuesRule::class =>
                new NormalizeTemporalValuesRuleConstraints(
                    [], [], [],
                    ['%session%','%tmp%','%temp%','%cache%'],
                    'null',
                    '1970-01-01 00:00:00',
                    true
                ),
            ConvertTemporalColumnsToDatetimeRule::class =>
                new ConvertTemporalColumnsToDatetimeRuleConstraints(),
        ],
    ]);
};