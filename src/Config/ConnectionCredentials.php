<?php

namespace Indoctrinate\Config;

final readonly class ConnectionCredentials
{
    public function __construct(
        private string $driver,
        private string $host,
        private int $port,
        private string $database,
        private string $user,
        private string $password
    )
    {
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

}