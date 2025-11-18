<?php

namespace ZPMLabs\SshManager\Factories;

use ZPMLabs\SshManager\Contracts\SshManagerContract;
use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Enums\OperatingSystem;
use ZPMLabs\SshManager\Exceptions\UnsupportedOperatingSystemException;
use ZPMLabs\SshManager\Providers\AndroidSshManagerProvider;
use ZPMLabs\SshManager\Providers\LinuxSshManagerProvider;
use ZPMLabs\SshManager\Providers\MacOsSshManagerProvider;
use ZPMLabs\SshManager\Providers\WindowsSshManagerProvider;
use ZPMLabs\SshManager\Services\SystemDetectorService;

class SshManagerFactory
{
    public static function make(
        SshRepositoryContract $repository,
        ?OperatingSystem $os = null,
    ): SshManagerContract {
        $os ??= SystemDetectorService::detect();

        return match ($os) {
            OperatingSystem::LINUX   => new LinuxSshManagerProvider($repository),
            OperatingSystem::WINDOWS => new WindowsSshManagerProvider($repository),
            OperatingSystem::MACOS   => new MacOsSshManagerProvider($repository),
            OperatingSystem::ANDROID => new AndroidSshManagerProvider($repository),
            default => throw new UnsupportedOperatingSystemException("Unsupported OS: {$os->value}"),
        };
    }
}
