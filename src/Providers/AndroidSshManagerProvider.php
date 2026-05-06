<?php

namespace ZPMPackages\SshManager\Providers;

use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;

class AndroidSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(
        SshRepositoryContract $repository,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    )
    {
        parent::__construct($repository, $managerCredentials);
        echo "[Android Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::ANDROID;
    }

    public function listSystemUsernames(): array
    {
        $username = get_current_user() ?: 'termux';

        return [$username];
    }

    public function listEntries(?string $ownerId = null): array
    {
        echo "[Android Provider] Listing entries" . ($ownerId ? " for owner: {$ownerId}" : "") . "\n";
        $entries = parent::listEntries($ownerId);
        echo "[Android Provider] Found " . count($entries) . " entries\n";
        return $entries;
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Android Provider] Creating entry for user: {$entry->getUsername()}\n";

        $keyEntity = $this->generateKeyPairForUser(
            $entry->getUsername(),
            $entry->getName() ?? $entry->getComment(),
            $this->normalizeKeyType($entry->getKeyType()),
            $entry->getKeyBits(),
            $entry->getKeyPassphrase(),
        );

        $entryWithKeys = $this->buildManagedEntryFromGeneratedKey($entry, $keyEntity);

        $created = $this->repository->create($entryWithKeys);
        
        echo "[Android Provider] Entry created with ID: {$created->getId()}\n";
        echo "[Android Provider] Public key path: {$created->getPublicKeyPath()}\n";
        echo "[Android Provider] Private key path: {$created->getPrivateKeyPath()}\n";
        
        $this->sync();
        
        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Android Provider] Updating entry: {$entry->getId()}\n";
        
        $existing = $this->repository->find($entry->getId());
        if (!$existing) {
            throw new \RuntimeException("Entry not found: {$entry->getId()}");
        }
        
        if ($this->shouldRegenerateKeyMaterial($existing, $entry)) {
            echo "[Android Provider] Username changed, regenerating keys\n";
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
        
        echo "[Android Provider] Entry updated: {$updated->getId()}\n";
        
        $this->sync();
        
        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        echo "[Android Provider] Deleting entry: {$id}\n";
        
        $entry = $this->repository->find($id);
        if ($entry) {
            echo "[Android Provider] Entry found, removing from repository\n";
            echo "[Android Provider] Keys kept on system at: {$entry->getPrivateKeyPath()}\n";
        }
        
        $this->repository->delete($id);
        
        echo "[Android Provider] Entry deleted: {$id}\n";
        
        $this->sync();
    }

    public function scanSystemUsers(): array
    {
        echo "[Android Provider] Scanning system users\n";
        $entries = [];
        
        // Android/Termux typically uses /data/data/com.termux/files/home
        $termuxHome = '/data/data/com.termux/files/home';
        $home = is_dir($termuxHome) ? $termuxHome : (getenv('HOME') ?: '/data/data/com.termux/files/home');
        
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        $pubPath = $sshDir . '/id_ed25519.pub';
        $privPath = $sshDir . '/id_ed25519';
        $publicKey = is_readable($pubPath)
            ? trim((string) file_get_contents($pubPath))
            : null;
        $username = get_current_user() ?: 'termux';

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
        
        echo "[Android Provider] Scanned " . count($entries) . " users\n";
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
        echo "[Android Provider] Generating key pair for user: {$systemUsername}\n";

        $keyType = $this->normalizeKeyType($keyType);
        
        // Android/Termux home directory
        $termuxHome = '/data/data/com.termux/files/home';
        $home = is_dir($termuxHome) ? $termuxHome : (getenv('HOME') ?: '/data/data/com.termux/files/home');
        
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        if (!is_dir($sshDir)) {
            echo "[Android Provider] Creating .ssh directory: {$sshDir}\n";
            $this->createDirectory($sshDir);
        }
        
        [$keyFileBase, $publicKeyPath] = $this->resolveManagedKeyPaths($home, $label, $keyType, '/', $publicKeyPath, $privateKeyPath);
        
        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[Android Provider] Key already exists: {$keyFileBase}\n";
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
        
        echo "[Android Provider] Executing: {$command}\n";
        
        $result = $this->executeCommand($cmd);

        if ($result['exitCode'] !== 0) {
            $error = implode("\n", $result['output']);
            echo "[Android Provider] Error generating key: {$error}\n";
            $this->ensureCommandSucceeded($result, 'ssh-keygen');
        }
        
        echo "[Android Provider] Key generated successfully\n";
        
        $privateKeyPath = $keyFileBase;
        
        $publicKey = $this->readFileContents($publicKeyPath);
        
        echo "[Android Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";
        
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
        echo "[Android Provider] Syncing SSH configuration\n";
        $this->syncAuthorizedKeysEntries();
        echo "[Android Provider] Sync completed\n";
    }
}
