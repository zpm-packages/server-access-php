<?php

namespace ZPMLabs\SshManager\Contracts;

use ZPMLabs\SshManager\Entities\SshEntryEntity;

interface SshManagerContract
{
    /**
     * List all stored SSH entries (from repository).
     *
     * @return SshEntryEntity[]
     */
    public function listEntries(?string $ownerId = null): array;

    /**
     * Get single SSH entry by id.
     */
    public function findEntry(string $id): ?SshEntryEntity;

    /**
     * CRUD in repository + OS sync.
     */
    public function createEntry(SshEntryEntity $entry): SshEntryEntity;
    public function updateEntry(SshEntryEntity $entry): SshEntryEntity;
    public function deleteEntry(string $id): void;

    /**
     * Scan actual OS and return discovered SSH-related users/keys.
     * This does NOT have to be the same as repository content.
     *
     * @return SshEntryEntity[]
     */
    public function scanSystemUsers(): array;

    /**
     * Generate OS-level SSH key pair for given system user and
     * create a matching SshEntryEntity in repository.
     */
    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity;
}
