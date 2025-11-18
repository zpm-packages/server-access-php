<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Enums\OperatingSystem;

class AndroidSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(SshRepositoryContract $repository)
    {
        parent::__construct($repository);
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::ANDROID;
    }

    public function scanSystemUsers(): array
    {
        // TODO: Implement Android-specific user/key scanning (e.g. Termux).
        return [];
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        // TODO: Implement Android-specific key generation logic.
        throw new \RuntimeException('generateKeyPairForUser is not implemented for Android yet.');
    }
}
