<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contract\SshManagerContract;
use ZPMLabs\SshManager\Contract\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;

abstract class AbstractSshManagerProvider implements SshManagerContract
{
    public function __construct(
        protected SshRepositoryContract $repository,
    ) {}

    abstract public function getOsName(): string;

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
     */
    public function sync(): void
    {
        // Default no-op; OS providers override.
    }

    // scanSystemUsers + generateKeyPairForUser će OS provider implementirati
}
