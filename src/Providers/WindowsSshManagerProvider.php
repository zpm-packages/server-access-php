<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Enums\OperatingSystem;

class WindowsSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(SshRepositoryContract $repository)
    {
        parent::__construct($repository);
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::WINDOWS;
    }

    public function scanSystemUsers(): array
    {
        // TODO: Implement Windows-specific user/key scanning.
        return [];
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        // TODO: Implement Windows-specific key generation logic.
        throw new \RuntimeException('generateKeyPairForUser is not implemented for Windows yet.');
    }
}
