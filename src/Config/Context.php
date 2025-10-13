<?php

namespace Indoctrinate\Config;

final readonly class Context
{
    public function __construct(
        private bool $isDry = false,
        private bool $isProd = false,
        private string $logDir = '',
        private string $configFilePath = '',
        private null|string $dsn = null,
    )
    {
    }

    public function isDry(): bool
    {
        return $this->isDry;
    }

    public function isProd(): bool
    {
        return $this->isProd;
    }

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    public function getConfigFilePath(): string
    {
        return $this->configFilePath;
    }

    public function getDsn(): null|string
    {
        return $this->dsn;
    }

}