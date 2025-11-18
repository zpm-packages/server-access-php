<?php

namespace ZPMLabs\SshManager\Entities;

class SshEntryEntity
{
    public function __construct(
        protected string $id,
        protected string $username,
        protected ?string $name = null,
        protected ?string $homeDirectory = null,
        protected ?string $publicKeyPath = null,
        protected ?string $privateKeyPath = null,
        protected ?string $publicKey = null,   // optional content
        protected ?string $comment = null,
        /** @var string[] */
        protected array $groups = [],
        protected ?string $ownerId = null,
        /** @var SshPermissionEntity[] */
        protected array $permissions = [],
    ) {}

    public function getId(): string              { return $this->id; }
    public function getUsername(): string        { return $this->username; }
    public function getName(): ?string           { return $this->name; }
    public function getHomeDirectory(): ?string  { return $this->homeDirectory; }
    public function getPublicKeyPath(): ?string  { return $this->publicKeyPath; }
    public function getPrivateKeyPath(): ?string { return $this->privateKeyPath; }
    public function getPublicKey(): ?string      { return $this->publicKey; }
    public function getComment(): ?string        { return $this->comment; }

    /**
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /**
     * @return SshPermissionEntity[]
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function withPermissions(array $permissions): self
    {
        $clone = clone $this;
        $clone->permissions = $permissions;

        return $clone;
    }
}
