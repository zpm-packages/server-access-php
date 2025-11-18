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
        echo "[Android Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::ANDROID;
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
        
        // Generate SSH keys on the system
        $keyEntity = $this->generateKeyPairForUser(
            $entry->getUsername(),
            $entry->getName() ?? $entry->getComment(),
            'ed25519',
            null
        );
        
        // Merge key paths into the entry
        $entryWithKeys = new SshEntryEntity(
            id: $entry->getId() ?: $keyEntity->getId(),
            username: $entry->getUsername(),
            name: $entry->getName() ?? $keyEntity->getName(),
            homeDirectory: $keyEntity->getHomeDirectory(),
            publicKeyPath: $keyEntity->getPublicKeyPath(),
            privateKeyPath: $keyEntity->getPrivateKeyPath(),
            publicKey: $keyEntity->getPublicKey(),
            comment: $entry->getComment() ?? $keyEntity->getComment(),
            groups: $entry->getGroups(),
            ownerId: $entry->getOwnerId(),
            permissions: $entry->getPermissions(),
        );
        
        // Create entry in repository
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
        
        // Update keys if username changed
        if ($existing->getUsername() !== $entry->getUsername()) {
            echo "[Android Provider] Username changed, regenerating keys\n";
            $this->generateKeyPairForUser(
                $entry->getUsername(),
                $entry->getName() ?? $entry->getComment(),
                'ed25519',
                null
            );
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
        
        if (is_dir($sshDir)) {
            $pubPath = $sshDir . '/id_ed25519.pub';
            $privPath = $sshDir . '/id_ed25519';
            
            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;
            
            if ($publicKey) {
                $username = get_current_user() ?: 'termux';
                $entries[] = new SshEntryEntity(
                    id: $username . ':id_ed25519',
                    username: $username,
                    name: $username,
                    homeDirectory: $home,
                    publicKeyPath: $pubPath,
                    privateKeyPath: $privPath,
                    publicKey: $publicKey,
                    comment: null,
                    groups: [],
                    ownerId: null,
                    permissions: [],
                );
            }
        }
        
        echo "[Android Provider] Scanned " . count($entries) . " users with SSH keys\n";
        return $entries;
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        echo "[Android Provider] Generating key pair for user: {$systemUsername}\n";
        
        // Android/Termux home directory
        $termuxHome = '/data/data/com.termux/files/home';
        $home = is_dir($termuxHome) ? $termuxHome : (getenv('HOME') ?: '/data/data/com.termux/files/home');
        
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        if (!is_dir($sshDir)) {
            echo "[Android Provider] Creating .ssh directory: {$sshDir}\n";
            mkdir($sshDir, 0700, true);
        }
        
        $keyFileBase = $sshDir . '/id_' . $keyType;
        
        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[Android Provider] Key already exists: {$keyFileBase}\n";
            $publicKeyPath = $keyFileBase . '.pub';
            $publicKey = is_readable($publicKeyPath)
                ? trim((string) file_get_contents($publicKeyPath))
                : null;
            
            return new SshEntryEntity(
                id: $systemUsername . ':id_' . $keyType,
                username: $systemUsername,
                name: $label ?? 'Existing key',
                homeDirectory: $home,
                publicKeyPath: $publicKeyPath,
                privateKeyPath: $keyFileBase,
                publicKey: $publicKey,
                comment: $label ?? ($systemUsername . '@' . gethostname()),
                groups: [],
                ownerId: null,
                permissions: [],
            );
        }
        
        $comment = $label ?? ($systemUsername . '@' . gethostname());
        
        $cmd = [
            'ssh-keygen',
            '-t', $keyType,
            '-f', $keyFileBase,
            '-N', '',
            '-C', $comment,
        ];
        
        if ($bits !== null && $keyType !== 'ed25519') {
            $cmd[] = '-b';
            $cmd[] = (string) $bits;
        }
        
        $escaped = array_map('escapeshellarg', $cmd);
        $command = implode(' ', $escaped) . ' 2>&1';
        
        echo "[Android Provider] Executing: {$command}\n";
        
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        
        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            echo "[Android Provider] Error generating key: {$error}\n";
            throw new \RuntimeException(
                "ssh-keygen failed with code {$exitCode}: {$error}"
            );
        }
        
        echo "[Android Provider] Key generated successfully\n";
        
        $publicKeyPath = $keyFileBase . '.pub';
        $privateKeyPath = $keyFileBase;
        
        $publicKey = is_readable($publicKeyPath)
            ? trim((string) file_get_contents($publicKeyPath))
            : null;
        
        echo "[Android Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";
        
        $entity = new SshEntryEntity(
            id: $systemUsername . ':id_' . $keyType,
            username: $systemUsername,
            name: $label ?? 'Default key',
            homeDirectory: $home,
            publicKeyPath: $publicKeyPath,
            privateKeyPath: $privateKeyPath,
            publicKey: $publicKey,
            comment: $comment,
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        return $entity;
    }

    public function sync(): void
    {
        echo "[Android Provider] Syncing SSH configuration\n";
        echo "[Android Provider] Sync completed\n";
    }
}
