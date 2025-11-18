<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Enums\OperatingSystem;

class WindowsSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(SshRepositoryContract $repository)
    {
        parent::__construct($repository);
    }

    public function getOsName(): string
    {
        return OperatingSystem::WINDOWS;
    }

    public function sync(): void
    {
        // Here you would:
        // - read all entries from repository
        // - generate proper config / authorized_keys file(s)
        // - write to disk (e.g. /home/{user}/.ssh/authorized_keys)
        //
        // For now leave as placeholder; this is OS-specific implementation detail.
    }
}
