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

    // Optional: set the project root directory used as the base for --impact, --sql-dump,
    // and --migration output paths. Defaults to the current working directory.
    // $config->projectRootDir(__DIR__); // or an absolute path

    $config->connection(
        'mysql',
        '127.0.0.1',
        4406,
        '',
        '',
        '',
    );

    $config->sets([
        DoctrineCompatibilitySet::class => []
    ]);
};