<?php

namespace ZPMPackages\SshManager\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;
use ZPMPackages\SshManager\Providers\AbstractSshManagerProvider;
use ZPMPackages\SshManager\Providers\WindowsSshManagerProvider;
use ZPMPackages\SshManager\Repositories\InMemorySshRepository;

class ManagerCredentialExecutionTest extends TestCase
{
    public function testBuildsUnixCredentialCommandWrapper(): void
    {
        $provider = $this->makeProvider(
            OperatingSystem::LINUX,
            new SshManagerCredentialsEntity('manager-user', 'manager-password'),
        );

        $command = $provider->exposeBuildExecutionCommand(['ssh-keygen', '-t', 'ed25519']);

        $this->assertStringContainsString('su - ', $command);
        $this->assertStringContainsString('manager-user', $command);
        $this->assertStringContainsString('manager-password', $command);
        $this->assertStringContainsString('ssh-keygen', $command);
    }

    public function testBuildsWindowsCredentialCommandWrapper(): void
    {
        $provider = $this->makeProvider(
            OperatingSystem::WINDOWS,
            new SshManagerCredentialsEntity('manager-user', 'manager-password'),
        );

        $command = $provider->exposeBuildExecutionCommand(['ssh-keygen', '-t', 'ed25519']);

        $this->assertStringContainsString('Start-Process', $command);
        $this->assertStringContainsString('manager-user', $command);
        $this->assertStringContainsString('manager-password', $command);
        $this->assertStringContainsString('ssh-keygen', $command);
    }

    public function testBuildsPlainCommandWithoutCredentials(): void
    {
        $provider = $this->makeProvider(OperatingSystem::WINDOWS);

        $command = $provider->exposeBuildExecutionCommand(['ssh-keygen', '-t', 'ed25519']);

        $this->assertStringContainsString('ssh-keygen', $command);
        $this->assertStringContainsString('2>&1', $command);
        $this->assertStringNotContainsString('Start-Process', $command);
        $this->assertStringNotContainsString('su - ', $command);
    }

    public function testBuildsManagedKeyPathFromEnteredLabel(): void
    {
        $provider = $this->makeProvider(OperatingSystem::WINDOWS);

        $path = $provider->exposeResolveManagedKeyFileBase('C:\\Users\\tester\\.ssh', 'Work Laptop', 'ed25519', '\\');

        $this->assertSame('C:\\Users\\tester\\.ssh\\work-laptop', $path);
    }

    public function testResolvesCustomManagedKeyPathsInsideSshDirectory(): void
    {
        $provider = $this->makeProvider(OperatingSystem::WINDOWS);

        [$privatePath, $publicPath] = $provider->exposeResolveManagedKeyPaths(
            'C:\\Users\\tester',
            'Work Laptop',
            'ed25519',
            '\\',
            'custom\\work-laptop.pub',
            'custom\\work-laptop',
        );

        $this->assertSame('C:\\Users\\tester\\.ssh\\custom\\work-laptop', $privatePath);
        $this->assertSame('C:\\Users\\tester\\.ssh\\custom\\work-laptop.pub', $publicPath);
    }

    public function testWindowsKeyGenerationDoesNotFallBackToCurrentProcessHomeDirectory(): void
    {
        $provider = new class extends WindowsSshManagerProvider {
            public function __construct()
            {
                parent::__construct(new InMemorySshRepository());
            }

            protected function resolveUserHomeDirectory(string $username): string
            {
                return 'C:\\MissingProfiles\\' . $username;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve the home directory for Windows user [ghost-user]. The SSH key was not created.');

        $provider->generateKeyPairForUser('ghost-user');
    }

    private function makeProvider(
        OperatingSystem $operatingSystem,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    ): object {
        return new class ($operatingSystem, $managerCredentials) extends AbstractSshManagerProvider {
            public function __construct(
                private readonly OperatingSystem $operatingSystem,
                ?SshManagerCredentialsEntity $managerCredentials = null,
            ) {
                parent::__construct(new InMemorySshRepository(), $managerCredentials);
            }

            public function getOs(): OperatingSystem
            {
                return $this->operatingSystem;
            }

            public function scanSystemUsers(): array
            {
                return [];
            }

            public function generateKeyPairForUser(
                string $systemUsername,
                ?string $label = null,
                ?string $keyType = 'ed25519',
                ?int $bits = null,
                ?string $passphrase = null,
                ?string $publicKeyPath = null,
                ?string $privateKeyPath = null
            ): SshEntryEntity {
                return new SshEntryEntity(
                    id: $systemUsername,
                    username: $systemUsername,
                );
            }

            public function exposeBuildExecutionCommand(array $command): string
            {
                return $this->buildExecutionCommand($command);
            }

            public function exposeResolveManagedKeyFileBase(string $sshDirectory, ?string $label, string $keyType, string $directorySeparator): string
            {
                return $this->resolveManagedKeyFileBase($sshDirectory, $label, $keyType, $directorySeparator);
            }

            public function exposeResolveManagedKeyPaths(string $homeDirectory, ?string $label, string $keyType, string $directorySeparator, ?string $publicKeyPath = null, ?string $privateKeyPath = null): array
            {
                return $this->resolveManagedKeyPaths($homeDirectory, $label, $keyType, $directorySeparator, $publicKeyPath, $privateKeyPath);
            }
        };
    }
}