<?php

namespace ZPMLabs\SshManager\Contracts;

use ZPMLabs\SshManager\Entities\SshEntryEntity;

interface SshRepositoryContract
{
    public function create(SshEntryEntity $entry): SshEntryEntity;

    public function update(SshEntryEntity $entry): SshEntryEntity;

    public function delete(string $id): void;

    public function find(string $id): ?SshEntryEntity;

    /**
     * @return SshEntryEntity[]
     */
    public function all(?string $ownerId = null): array;
}
