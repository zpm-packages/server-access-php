<?php

namespace ZPMPackages\SshManager\Entities;

class SshEntryEntity
{
    public function __construct(
        protected string $id,
        protected string $username,
        protected ?string $name = null,
        protected ?string $homeDirectory = null,
        protected ?string $keyPassphrase = null,
        protected ?string $publicKeyPath = null,
        protected ?string $privateKeyPath = null,
        protected ?string $publicKey = null,   // optional content
        /** @var string[] */
        protected array $authorizedKeys = [],
        protected ?string $comment = null,
        /** @var string[] */
        protected array $groups = [],
        protected ?string $ownerId = null,
        protected ?string $keyType = 'ed25519',
        protected ?int $keyBits = null,
        protected bool $canReadEntries = true,
        protected bool $canWriteEntries = false,
        protected bool $canManageEntries = false,
        /** @var string[] */
        protected array $managedDirectories = [],
        /** @var SshPermissionEntity[] */
        protected array $permissions = [],
    ) {}

    public function getId(): string              { return $this->id; }
    public function getUsername(): string        { return $this->username; }
    public function getName(): ?string           { return $this->name; }
    public function getHomeDirectory(): ?string  { return $this->homeDirectory; }
    public function getKeyPassphrase(): ?string  { return $this->keyPassphrase; }
    public function getPublicKeyPath(): ?string  { return $this->publicKeyPath; }
    public function getPrivateKeyPath(): ?string { return $this->privateKeyPath; }
    public function getPublicKey(): ?string      { return $this->publicKey; }
    /**
     * @return string[]
     */
    public function getAuthorizedKeys(): array   { return $this->authorizedKeys; }
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

    public function getKeyType(): ?string
    {
        return $this->keyType;
    }

    public function getKeyBits(): ?int
    {
        return $this->keyBits;
    }

    public function canReadEntries(): bool
    {
        return $this->canReadEntries;
    }

    public function canWriteEntries(): bool
    {
        return $this->canWriteEntries;
    }

    public function canManageEntries(): bool
    {
        return $this->canManageEntries;
    }

    /**
     * @return string[]
     */
    public function getManagedDirectories(): array
    {
        return $this->managedDirectories;
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
