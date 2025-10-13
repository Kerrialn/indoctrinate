<?php

namespace Indoctrinate\Config;

final class ConnectionCredentials
{
    /**
     * @readonly
     */
    private string $driver;
    /**
     * @readonly
     */
    private string $host;
    /**
     * @readonly
     */
    private int $port;
    /**
     * @readonly
     */
    private string $database;
    /**
     * @readonly
     */
    private string $user;
    /**
     * @readonly
     */
    private string $password;
    public function __construct(string $driver, string $host, int $port, string $database, string $user, string $password)
    {
        $this->driver = $driver;
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
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