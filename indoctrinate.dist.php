<?php

use Indoctrinate\Config\IndoctrinateConfig;
use Indoctrinate\Set\MySQL\DoctrineCompatibilitySet;

return static function (IndoctrinateConfig $config): void {

    $config->setConnectionCredentials(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 4406,
        dbname: 'db_name',
        user: 'user',
        password: 'password',
    );

    $config->sets([
        DoctrineCompatibilitySet::class => []
    ]);

};