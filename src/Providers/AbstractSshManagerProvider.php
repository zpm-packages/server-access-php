<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshManagerContract;
use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Enums\OperatingSystem;

abstract class AbstractSshManagerProvider implements SshManagerContract
{
    public function __construct(
        protected SshRepositoryContract $repository,
    ) {}

    /**
     * Returns enum for current OS of this provider.
     */
    abstract public function getOs(): OperatingSystem;

    /**
     * Helper if we ever need plain string.
     */
    public function getOsName(): string
    {
        return $this->getOs()->value;
    }

    public function listEntries(?string $ownerId = null): array
    {
        return $this->repository->all($ownerId);
    }

    public function findEntry(string $id): ?SshEntryEntity
    {
        return $this->repository->find($id);
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $created = $this->repository->create($entry);

        $this->sync();

        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $updated = $this->repository->update($entry);

        $this->sync();

        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        $this->repository->delete($id);

        $this->sync();
    }

    /**
     * OS-specific sync: write authorized_keys, config files, etc.
     * Providers can override; by default we do nothing.
     */
    public function sync(): void
    {
        // Default no-op; OS providers override if needed.
    }

    /**
     * Must be implemented per OS – scanning real system users / keys.
     *
     * @return SshEntryEntity[]
     */
    abstract public function scanSystemUsers(): array;

    /**
     * Must be implemented per OS – generate SSH key pair for given system user.
     */
    abstract public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity;
}
