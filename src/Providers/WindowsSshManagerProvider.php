<?php

namespace ZPMPackages\SshManager\Providers;

use RuntimeException;
use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;

class WindowsSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(
        SshRepositoryContract $repository,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    )
    {
        parent::__construct($repository, $managerCredentials);
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::WINDOWS;
    }

    public function listSystemUsernames(): array
    {
        $result = $this->executeCommand([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            'Get-LocalUser | Select-Object -ExpandProperty Name',
        ]);

        if ($result['exitCode'] !== 0) {
            return [];
        }

        $usernames = array_values(array_filter(array_map(
            static fn (string $username): string => trim($username),
            $result['output'],
        )));

        sort($usernames);

        return array_values(array_unique($usernames));
    }

    public function verifyUserPassword(string $username, string $password): bool
    {
        if (trim($password) === '') {
            return false;
        }

        $script = sprintf(
            "try { " .
            "\$password = ConvertTo-SecureString '%s' -AsPlainText -Force; " .
            "\$credential = New-Object System.Management.Automation.PSCredential('.\\%s', \$password); " .
            "\$process = Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', 'exit') -Credential \$credential -LoadUserProfile -WindowStyle Hidden -Wait -PassThru -ErrorAction Stop; " .
            "exit 0 " .
            "} catch { exit 1 }",
            $this->escapePowerShellLiteral($password),
            $this->escapePowerShellLiteral($username),
        );

        $result = $this->executeCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);

        return $result['exitCode'] === 0;
    }

    public function updateUserPassword(string $username, string $newPassword): void
    {
        $script = sprintf(
            "\$password = ConvertTo-SecureString '%s' -AsPlainText -Force; Set-LocalUser -Name '%s' -Password \$password",
            $this->escapePowerShellLiteral($newPassword),
            $this->escapePowerShellLiteral($username),
        );

        $result = $this->executeCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);

        $this->ensureCommandSucceeded($result, 'update Windows user password [' . $username . ']');
    }

    public function listEntries(?string $ownerId = null): array
    {
        return parent::listEntries($ownerId);
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $entry = $this->ensureSystemUserExists($entry);

        $keyEntity = $this->generateKeyPairForUser(
            $entry->getUsername(),
            $entry->getName() ?? $entry->getComment(),
            $this->normalizeKeyType($entry->getKeyType()),
            $entry->getKeyBits(),
            $entry->getKeyPassphrase(),
        );

        $entryWithKeys = $this->buildManagedEntryFromGeneratedKey($entry, $keyEntity);

        $created = $this->repository->create($entryWithKeys);

        $this->sync();

        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $existing = $this->repository->find($entry->getId());
        if (!$existing) {
            throw new \RuntimeException("Entry not found: {$entry->getId()}");
        }

        $entry = $this->syncSystemUser($existing, $entry);

        if ($this->shouldRegenerateKeyMaterial($existing, $entry)) {
            $keyEntity = $this->generateKeyPairForUser(
                $entry->getUsername(),
                $entry->getName() ?? $entry->getComment(),
                $this->normalizeKeyType($entry->getKeyType()),
                $entry->getKeyBits(),
                $entry->getKeyPassphrase(),
            );

            $entry = $this->buildManagedEntryFromGeneratedKey($entry, $keyEntity);
        }

        $updated = $this->repository->update($entry);

        $this->sync();

        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        $entry = $this->repository->find($id);
        if ($entry) {
            $this->deleteSystemUser($entry->getUsername());
        }

        $this->repository->delete($id);

        $this->sync();
    }

    public function scanSystemUsers(): array
    {
        $entries = [];

        foreach ($this->listSystemUsernames() as $username) {
            $userPath = $this->resolveUserHomeDirectory($username);
            $sshDir = $userPath . '\\.ssh';
            $pubPath = $sshDir . '\\id_ed25519.pub';
            $privPath = $sshDir . '\\id_ed25519';

            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;

            $entries[] = new SshEntryEntity(
                id: $username,
                username: $username,
                name: $username,
                homeDirectory: $userPath,
                publicKeyPath: is_readable($pubPath) ? $pubPath : null,
                privateKeyPath: is_readable($privPath) ? $privPath : null,
                publicKey: $publicKey,
                authorizedKeys: $this->loadAuthorizedKeys($userPath),
                comment: $this->extractPublicKeyComment($publicKey),
                groups: $this->getUserGroups($username),
                ownerId: null,
                keyType: $publicKey ? 'ed25519' : null,
                keyBits: null,
                permissions: [],
            );
        }
        
        return $entries;
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null,
        ?string $passphrase = null,
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
    ): SshEntryEntity {
        $keyType = $this->normalizeKeyType($keyType);

        // Try to find user's directory
        $usersPath = $this->resolveUserHomeDirectory($systemUsername);
        if (! is_dir($usersPath)) {
            $existingEntry = null;

            foreach ($this->repository->all() as $entry) {
                if ($entry->getUsername() === $systemUsername) {
                    $existingEntry = $entry;

                    break;
                }
            }

            $usersPath = $existingEntry?->getHomeDirectory() ?? $usersPath;
        }

        if (! is_dir($usersPath)) {
            throw new RuntimeException('Unable to resolve the home directory for Windows user [' . $systemUsername . ']. The SSH key was not created.');
        }

        $sshDir = $usersPath . '\\.ssh';

        // Create .ssh directory if it doesn't exist
        if (!is_dir($sshDir)) {
            $this->createDirectory($sshDir);
        }

        [$keyFileBase, $publicKeyPath] = $this->resolveManagedKeyPaths($usersPath, $label, $keyType, '\\', $publicKeyPath, $privateKeyPath);

        // Check if key already exists
        if (file_exists($keyFileBase)) {
            // Read existing key
            $publicKey = $this->readFileContents($publicKeyPath);

            return new SshEntryEntity(
                id: $systemUsername . ':id_' . $keyType,
                username: $systemUsername,
                name: $label ?? 'Existing key',
                homeDirectory: $usersPath,
                publicKeyPath: $publicKeyPath,
                privateKeyPath: $keyFileBase,
                publicKey: $publicKey,
                authorizedKeys: $this->loadAuthorizedKeys($usersPath),
                comment: $label ?? $this->extractPublicKeyComment($publicKey) ?? ($systemUsername . '@' . gethostname()),
                groups: $this->getUserGroups($systemUsername),
                ownerId: null,
                keyType: $keyType,
                keyBits: $bits,
                permissions: [],
            );
        }

        $comment = $label ?? ($systemUsername . '@' . gethostname());

        // Use ssh-keygen (OpenSSH on Windows 10+)
        $cmd = sprintf(
            'ssh-keygen -t %s -f %s -N %s -C %s',
            escapeshellarg($keyType),
            escapeshellarg($keyFileBase),
            escapeshellarg($passphrase ?? ''),
            escapeshellarg($comment)
        );

        $result = $this->executeCommand([
            'ssh-keygen',
            '-t',
            $keyType,
            '-f',
            $keyFileBase,
            '-N',
            $passphrase ?? '',
            '-C',
            $comment,
        ]);

        if ($result['exitCode'] !== 0) {
            $this->ensureCommandSucceeded($result, 'ssh-keygen');
        }

        $privateKeyPath = $keyFileBase;

        $publicKey = $this->readFileContents($publicKeyPath);

        $entity = new SshEntryEntity(
            id: $systemUsername . ':id_' . $keyType,
            username: $systemUsername,
            name: $label ?? 'Default key',
            homeDirectory: $usersPath,
            publicKeyPath: $publicKeyPath,
            privateKeyPath: $privateKeyPath,
            publicKey: $publicKey,
            authorizedKeys: $this->loadAuthorizedKeys($usersPath),
            comment: $comment,
            groups: $this->getUserGroups($systemUsername),
            ownerId: null,
            keyType: $keyType,
            keyBits: $bits,
            permissions: [],
        );
        
        return $entity;
    }

    public function sync(): void
    {
        $this->syncAuthorizedKeysEntries();
    }

    protected function ensureSystemUserExists(SshEntryEntity $entry): SshEntryEntity
    {
        if (! $this->systemUserExists($entry->getUsername())) {
            $script = "if (-not (Get-LocalUser -Name '" . $this->escapePowerShellLiteral($entry->getUsername()) . "' -ErrorAction SilentlyContinue)) { New-LocalUser -Name '" . $this->escapePowerShellLiteral($entry->getUsername()) . "' -NoPassword -FullName '" . $this->escapePowerShellLiteral($entry->getName() ?? $entry->getUsername()) . "' | Out-Null }";

            $result = $this->executeCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);

            $this->ensureCommandSucceeded($result, 'create Windows user [' . $entry->getUsername() . ']');
        }

        $homeDirectory = $entry->getHomeDirectory() ?? $this->resolveUserHomeDirectory($entry->getUsername());
        $this->createDirectory($homeDirectory);

        return new SshEntryEntity(
            id: $entry->getId(),
            username: $entry->getUsername(),
            name: $entry->getName(),
            homeDirectory: $homeDirectory,
            publicKeyPath: $entry->getPublicKeyPath(),
            privateKeyPath: $entry->getPrivateKeyPath(),
            publicKey: $entry->getPublicKey(),
            authorizedKeys: $entry->getAuthorizedKeys(),
            comment: $entry->getComment(),
            groups: $entry->getGroups(),
            ownerId: $entry->getOwnerId(),
            keyType: $entry->getKeyType(),
            keyBits: $entry->getKeyBits(),
            canReadEntries: $entry->canReadEntries(),
            canWriteEntries: $entry->canWriteEntries(),
            canManageEntries: $entry->canManageEntries(),
            managedDirectories: $entry->getManagedDirectories(),
            permissions: $entry->getPermissions(),
        );
    }

    protected function syncSystemUser(SshEntryEntity $existing, SshEntryEntity $entry): SshEntryEntity
    {
        if (! $this->hasManagerCredentials()) {
            return $this->ensureSystemUserExists($entry);
        }

        if ($existing->getUsername() !== $entry->getUsername() && $this->systemUserExists($existing->getUsername())) {
            $renameScript = "Get-LocalUser -Name '" . $this->escapePowerShellLiteral($existing->getUsername()) . "' | Rename-LocalUser -NewName '" . $this->escapePowerShellLiteral($entry->getUsername()) . "'";
            $renameResult = $this->executeCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command', $renameScript]);
            $this->ensureCommandSucceeded($renameResult, 'rename Windows user [' . $existing->getUsername() . ']');
        }

        if (($entry->getName() !== null) && ($entry->getName() !== '')) {
            $fullNameScript = "Set-LocalUser -Name '" . $this->escapePowerShellLiteral($entry->getUsername()) . "' -FullName '" . $this->escapePowerShellLiteral($entry->getName()) . "'";
            $fullNameResult = $this->executeCommand(['powershell', '-NoProfile', '-NonInteractive', '-Command', $fullNameScript]);
            $this->ensureCommandSucceeded($fullNameResult, 'update Windows user [' . $entry->getUsername() . ']');
        }

        return $this->ensureSystemUserExists($entry);
    }

    protected function deleteSystemUser(string $username): void
    {
        if ((! $this->hasManagerCredentials()) || (! $this->systemUserExists($username))) {
            return;
        }

        $result = $this->executeCommand(['net', 'user', $username, '/delete']);

        $this->ensureCommandSucceeded($result, 'delete Windows user [' . $username . ']');
    }

    protected function systemUserExists(string $username): bool
    {
        $result = $this->executeCommand(['net', 'user', $username]);

        return $result['exitCode'] === 0;
    }

    protected function resolveUserHomeDirectory(string $username): string
    {
        return (getenv('SystemDrive') ?: 'C:') . '\\Users\\' . $username;
    }

    /**
     * @return string[]
     */
    protected function getUserGroups(string $username): array
    {
        $result = $this->executeCommand(['net', 'user', $username]);

        if ($result['exitCode'] !== 0 || empty($result['output'])) {
            return [];
        }

        return $this->parseNetUserGroups($result['output']);
    }

    /**
     * @param string[] $output
     * @return string[]
     */
    protected function parseNetUserGroups(array $output): array
    {
        $groups = [];
        $capture = false;

        foreach ($output as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                if ($capture) {
                    break;
                }

                continue;
            }

            foreach (['Local Group Memberships', 'Global Group memberships'] as $label) {
                if (str_starts_with($trimmedLine, $label)) {
                    $capture = true;

                    $line = trim(substr($trimmedLine, strlen($label)));
                    if ($line !== '') {
                        $groups = [...$groups, ...$this->extractGroupsFromLine($line)];
                    }

                    continue 2;
                }
            }

            if ($capture) {
                if (str_contains($trimmedLine, 'The command completed successfully')) {
                    break;
                }

                $groups = [...$groups, ...$this->extractGroupsFromLine($trimmedLine)];
            }
        }

        return array_values(array_unique(array_filter($groups)));
    }

    /**
     * @return string[]
     */
    protected function extractGroupsFromLine(string $line): array
    {
        preg_match_all('/\*([^*]+)/', $line, $matches);

        if (! isset($matches[1])) {
            return [];
        }

        return array_map(
            static fn (string $group): string => trim($group),
            $matches[1],
        );
    }
}
