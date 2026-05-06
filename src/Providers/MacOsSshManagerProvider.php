<?php

namespace ZPMPackages\SshManager\Providers;

use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;

class MacOsSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(
        SshRepositoryContract $repository,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    )
    {
        parent::__construct($repository, $managerCredentials);
        echo "[macOS Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::MACOS;
    }

    public function listSystemUsernames(): array
    {
        $result = $this->executeCommand(['dscl', '.', '-list', '/Users']);

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

        $command = sprintf(
            "printf '%%s\\n' %s | su - %s -c %s 2>&1",
            escapeshellarg($password),
            escapeshellarg($username),
            escapeshellarg('true'),
        );

        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    public function updateUserPassword(string $username, string $newPassword): void
    {
        $result = $this->executeCommand(['dscl', '.', '-passwd', '/Users/' . $username, $newPassword]);

        $this->ensureCommandSucceeded($result, 'update macOS user password [' . $username . ']');
    }

    public function listEntries(?string $ownerId = null): array
    {
        echo "[macOS Provider] Listing entries" . ($ownerId ? " for owner: {$ownerId}" : "") . "\n";
        $entries = parent::listEntries($ownerId);
        echo "[macOS Provider] Found " . count($entries) . " entries\n";
        return $entries;
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[macOS Provider] Creating entry for user: {$entry->getUsername()}\n";

        $keyEntity = $this->generateKeyPairForUser(
            $entry->getUsername(),
            $entry->getName() ?? $entry->getComment(),
            $this->normalizeKeyType($entry->getKeyType()),
            $entry->getKeyBits(),
            $entry->getKeyPassphrase(),
        );

        $entryWithKeys = $this->buildManagedEntryFromGeneratedKey($entry, $keyEntity);

        $created = $this->repository->create($entryWithKeys);
        
        echo "[macOS Provider] Entry created with ID: {$created->getId()}\n";
        echo "[macOS Provider] Public key path: {$created->getPublicKeyPath()}\n";
        echo "[macOS Provider] Private key path: {$created->getPrivateKeyPath()}\n";
        
        $this->sync();
        
        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[macOS Provider] Updating entry: {$entry->getId()}\n";
        
        $existing = $this->repository->find($entry->getId());
        if (!$existing) {
            throw new \RuntimeException("Entry not found: {$entry->getId()}");
        }
        
        if ($this->shouldRegenerateKeyMaterial($existing, $entry)) {
            echo "[macOS Provider] Username changed, regenerating keys\n";
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
        
        echo "[macOS Provider] Entry updated: {$updated->getId()}\n";
        
        $this->sync();
        
        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        echo "[macOS Provider] Deleting entry: {$id}\n";
        
        $entry = $this->repository->find($id);
        if ($entry) {
            echo "[macOS Provider] Entry found, removing from repository\n";
            echo "[macOS Provider] Keys kept on system at: {$entry->getPrivateKeyPath()}\n";
        }
        
        $this->repository->delete($id);
        
        echo "[macOS Provider] Entry deleted: {$id}\n";
        
        $this->sync();
    }

    public function scanSystemUsers(): array
    {
        echo "[macOS Provider] Scanning system users\n";
        $entries = [];

        foreach ($this->listSystemUsernames() as $username) {
            $pw = function_exists('posix_getpwnam') ? posix_getpwnam($username) : null;

            if (! $pw || ! isset($pw['dir'])) {
                continue;
            }

            $home = $pw['dir'];
            $sshDir = rtrim($home, '/') . '/.ssh';
            $pubPath = $sshDir . '/id_ed25519.pub';
            $privPath = $sshDir . '/id_ed25519';

            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;

            $entries[] = new SshEntryEntity(
                id: $username,
                username: $username,
                name: $username,
                homeDirectory: $home,
                publicKeyPath: is_readable($pubPath) ? $pubPath : null,
                privateKeyPath: is_readable($privPath) ? $privPath : null,
                publicKey: $publicKey,
                authorizedKeys: $this->loadAuthorizedKeys($home),
                comment: null,
                groups: [],
                ownerId: null,
                keyType: $publicKey ? 'ed25519' : null,
                keyBits: null,
                permissions: [],
            );
        }
        
        echo "[macOS Provider] Scanned " . count($entries) . " users\n";
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
        echo "[macOS Provider] Generating key pair for user: {$systemUsername}\n";

        $keyType = $this->normalizeKeyType($keyType);
        
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($systemUsername) : null;
        
        if (!$pw || !isset($pw['dir'])) {
            throw new \RuntimeException("System user '{$systemUsername}' not found.");
        }
        
        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        if (!is_dir($sshDir)) {
            echo "[macOS Provider] Creating .ssh directory: {$sshDir}\n";
            $this->createDirectory($sshDir);
        }
        
        [$keyFileBase, $publicKeyPath] = $this->resolveManagedKeyPaths($home, $label, $keyType, '/', $publicKeyPath, $privateKeyPath);
        
        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[macOS Provider] Key already exists: {$keyFileBase}\n";
            $publicKey = $this->readFileContents($publicKeyPath);
            
            return new SshEntryEntity(
                id: $systemUsername . ':id_' . $keyType,
                username: $systemUsername,
                name: $label ?? 'Existing key',
                homeDirectory: $home,
                publicKeyPath: $publicKeyPath,
                privateKeyPath: $keyFileBase,
                publicKey: $publicKey,
                authorizedKeys: $this->loadAuthorizedKeys($home),
                comment: $label ?? ($systemUsername . '@' . gethostname()),
                groups: [],
                ownerId: null,
                keyType: $keyType,
                keyBits: $bits,
                permissions: [],
            );
        }
        
        $comment = $label ?? ($systemUsername . '@' . gethostname());
        
        $cmd = [
            'ssh-keygen',
            '-t', $keyType,
            '-f', $keyFileBase,
            '-N', $passphrase ?? '',
            '-C', $comment,
        ];
        
        if ($bits !== null && $keyType !== 'ed25519') {
            $cmd[] = '-b';
            $cmd[] = (string) $bits;
        }
        
        $command = $this->buildExecutionCommand($cmd);
        
        echo "[macOS Provider] Executing: {$command}\n";
        
        $result = $this->executeCommand($cmd);

        if ($result['exitCode'] !== 0) {
            $error = implode("\n", $result['output']);
            echo "[macOS Provider] Error generating key: {$error}\n";
            $this->ensureCommandSucceeded($result, 'ssh-keygen');
        }
        
        echo "[macOS Provider] Key generated successfully\n";
        
        $privateKeyPath = $keyFileBase;
        
        $publicKey = $this->readFileContents($publicKeyPath);
        
        echo "[macOS Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";
        
        $entity = new SshEntryEntity(
            id: $systemUsername . ':id_' . $keyType,
            username: $systemUsername,
            name: $label ?? 'Default key',
            homeDirectory: $home,
            publicKeyPath: $publicKeyPath,
            privateKeyPath: $privateKeyPath,
            publicKey: $publicKey,
            authorizedKeys: $this->loadAuthorizedKeys($home),
            comment: $comment,
            groups: [],
            ownerId: null,
            keyType: $keyType,
            keyBits: $bits,
            permissions: [],
        );
        
        return $entity;
    }

    public function sync(): void
    {
        echo "[macOS Provider] Syncing SSH configuration\n";
        $this->syncAuthorizedKeysEntries();
        echo "[macOS Provider] Sync completed\n";
    }
}
