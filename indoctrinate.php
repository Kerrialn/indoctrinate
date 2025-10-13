<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Rule\Integrity\Constraint\EnsurePrimaryKeyUuidRuleConstraints;
use Indoctrinate\Rule\Integrity\Constraint\EnsureUnifiedPrimaryKeyNameRuleConstraints;
use Indoctrinate\Rule\Integrity\EnsurePrimaryKeyUuidRule;
use Indoctrinate\Rule\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use Indoctrinate\Rule\Normalization\Constraint\SlugifyFieldRuleConstraints;
use Indoctrinate\Rule\Normalization\SlugifyFieldRule;
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

    $config->rules([
        SlugifyFieldRule::class => new SlugifyFieldRuleConstraints('default_fields_field', 'field_text', 'slug', 255)
    ]);
};