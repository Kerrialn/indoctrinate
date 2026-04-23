<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\ConvertTemporalColumnsToDatetimeRuleConstraints;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use Indoctrinate\Rule\MySQL\Integrity\Constraint\NormalizeTemporalValuesRuleConstraints;
use Indoctrinate\Rule\MySQL\Integrity\ConvertTemporalColumnsToDatetimeRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Rule\MySQL\Integrity\NormalizeTemporalValuesRule;
use Indoctrinate\Rule\MySQL\Normalization\Constraint\SlugifyFieldRuleConstraints;
use Indoctrinate\Rule\MySQL\Normalization\SlugifyFieldRule;
use Indoctrinate\Set\MySQL\DoctrineCompatibilitySet;
use Indoctrinate\Set\MySQL\EnsureDateTimeSet;
use Indoctrinate\Set\MySQL\EnsurePrimaryKeyUuidSet;

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
        DoctrineCompatibilitySet::class => []
    ]);
};