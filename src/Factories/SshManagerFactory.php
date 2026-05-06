<?php

namespace ZPMPackages\SshManager\Factories;

use ZPMPackages\SshManager\Contracts\SshManagerContract;
use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;
use ZPMPackages\SshManager\Exceptions\UnsupportedOperatingSystemException;
use ZPMPackages\SshManager\Providers\AndroidSshManagerProvider;
use ZPMPackages\SshManager\Providers\LinuxSshManagerProvider;
use ZPMPackages\SshManager\Providers\MacOsSshManagerProvider;
use ZPMPackages\SshManager\Providers\WindowsSshManagerProvider;
use ZPMPackages\SshManager\Services\SystemDetectorService;

class SshManagerFactory
{
    public static function make(
        SshRepositoryContract $repository,
        ?OperatingSystem $os = null,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    ): SshManagerContract {
        $os ??= SystemDetectorService::detect();

        return match ($os) {
            OperatingSystem::LINUX   => new LinuxSshManagerProvider($repository, $managerCredentials),
            OperatingSystem::WINDOWS => new WindowsSshManagerProvider($repository, $managerCredentials),
            OperatingSystem::MACOS   => new MacOsSshManagerProvider($repository, $managerCredentials),
            OperatingSystem::ANDROID => new AndroidSshManagerProvider($repository, $managerCredentials),
            default => throw new UnsupportedOperatingSystemException("Unsupported OS: {$os->value}"),
        };
    }
}
