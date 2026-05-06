<?php

namespace ZPMPackages\SshManager\Contracts;

use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;

interface SshManagerContract
{
    /**
     * Credentials for the privileged account that manages other system users.
     */
    public function getManagerCredentials(): ?SshManagerCredentialsEntity;

    public function hasManagerCredentials(): bool;

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
        ?int $bits = null,
        ?string $passphrase = null,
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
    ): SshEntryEntity;

    /**
     * @return string[]
     */
    public function listSystemUsernames(): array;

    public function verifyUserPassword(string $username, string $password): bool;

    public function updateUserPassword(string $username, string $newPassword): void;
}
