<?php

namespace Indoctrinate\Config;

final class Context
{
    /**
     * @readonly
     */
    private bool $isDry = false;
    /**
     * @readonly
     */
    private bool $isProd = false;
    /**
     * @readonly
     */
    private ?string $logDir = null;
    /**
     * @readonly
     */
    private string $configFilePath = '';
    /**
     * @readonly
     * @var string|null
     */
    private $dsn = null;

    public function __construct(bool $isDry = false, bool $isProd = false, ?string $logDir = null, string $configFilePath = '', ?string $dsn = null)
    {
        $this->isDry = $isDry;
        $this->isProd = $isProd;
        $this->logDir = $logDir;
        $this->configFilePath = $configFilePath;
        $this->dsn = $dsn;
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

    public function getDsn(): ?string
    {
        return $this->dsn;
    }

}