<?php

namespace ZPMPackages\SshManager\Entities;

class SshManagerCredentialsEntity
{
    public function __construct(
        protected string $username,
        protected ?string $password = null,
        protected ?string $host = null,
        protected ?int $port = null,
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function hasPassword(): bool
    {
        return is_string($this->password) && trim($this->password) !== '';
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function hasRemoteHost(): bool
    {
        return is_string($this->host) && trim($this->host) !== '';
    }
}