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
        echo "[Windows Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::WINDOWS;
    }

    public function listEntries(?string $ownerId = null): array
    {
        echo "[Windows Provider] Listing entries" . ($ownerId ? " for owner: {$ownerId}" : "") . "\n";
        $entries = parent::listEntries($ownerId);
        echo "[Windows Provider] Found " . count($entries) . " entries\n";
        return $entries;
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Windows Provider] Creating entry for user: {$entry->getUsername()}\n";
        
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
        
        echo "[Windows Provider] Entry created with ID: {$created->getId()}\n";
        echo "[Windows Provider] Public key path: {$created->getPublicKeyPath()}\n";
        echo "[Windows Provider] Private key path: {$created->getPrivateKeyPath()}\n";
        
        $this->sync();
        
        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Windows Provider] Updating entry: {$entry->getId()}\n";
        
        $existing = $this->repository->find($entry->getId());
        if (!$existing) {
            throw new \RuntimeException("Entry not found: {$entry->getId()}");
        }
        
        // Update keys if username changed
        if ($existing->getUsername() !== $entry->getUsername()) {
            echo "[Windows Provider] Username changed, regenerating keys\n";
            $this->generateKeyPairForUser(
                $entry->getUsername(),
                $entry->getName() ?? $entry->getComment(),
                'ed25519',
                null
            );
        }
        
        $updated = $this->repository->update($entry);
        
        echo "[Windows Provider] Entry updated: {$updated->getId()}\n";
        
        $this->sync();
        
        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        echo "[Windows Provider] Deleting entry: {$id}\n";
        
        $entry = $this->repository->find($id);
        if ($entry) {
            // Optionally remove keys from system (we'll keep them for safety)
            echo "[Windows Provider] Entry found, removing from repository\n";
            echo "[Windows Provider] Keys kept on system at: {$entry->getPrivateKeyPath()}\n";
        }
        
        $this->repository->delete($id);
        
        echo "[Windows Provider] Entry deleted: {$id}\n";
        
        $this->sync();
    }

    public function scanSystemUsers(): array
    {
        echo "[Windows Provider] Scanning system users\n";
        $entries = [];
        
        // Get user profiles directory
        $usersPath = getenv('SystemDrive') . '\\Users';
        if (!is_dir($usersPath)) {
            echo "[Windows Provider] Users directory not found: {$usersPath}\n";
            return $entries;
        }
        
        $dirs = scandir($usersPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === 'Public' || $dir === 'Default') {
                continue;
            }
            
            $userPath = $usersPath . '\\' . $dir;
            if (!is_dir($userPath)) {
                continue;
            }
            
            $sshDir = $userPath . '\\.ssh';
            if (!is_dir($sshDir)) {
                continue;
            }
            
            $pubPath = $sshDir . '\\id_ed25519.pub';
            $privPath = $sshDir . '\\id_ed25519';
            
            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;
            
            if ($publicKey) {
                $entries[] = new SshEntryEntity(
                    id: $dir . ':id_ed25519',
                    username: $dir,
                    name: $dir,
                    homeDirectory: $userPath,
                    publicKeyPath: is_readable($pubPath) ? $pubPath : null,
                    privateKeyPath: is_readable($privPath) ? $privPath : null,
                    publicKey: $publicKey,
                    comment: null,
                    groups: [],
                    ownerId: null,
                    permissions: [],
                );
            }
        }
        
        echo "[Windows Provider] Scanned " . count($entries) . " users with SSH keys\n";
        return $entries;
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        echo "[Windows Provider] Generating key pair for user: {$systemUsername}\n";
        
        // Get user's home directory
        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');
        
        // Try to find user's directory
        $usersPath = getenv('SystemDrive') . '\\Users\\' . $systemUsername;
        if (!is_dir($usersPath)) {
            // Fallback to current user's home if username not found
            $currentUserHome = ($homeDrive ?: 'C:') . ($homePath ?: '\\Users\\' . getenv('USERNAME'));
            if (is_dir($currentUserHome)) {
                $usersPath = $currentUserHome;
            } else {
                $usersPath = ($homeDrive ?: 'C:') . ($homePath ?: '\\Users\\' . $systemUsername);
            }
            echo "[Windows Provider] Using path: {$usersPath}\n";
        }
        
        $sshDir = $usersPath . '\\.ssh';
        
        // Create .ssh directory if it doesn't exist
        if (!is_dir($sshDir)) {
            echo "[Windows Provider] Creating .ssh directory: {$sshDir}\n";
            mkdir($sshDir, 0700, true);
        }
        
        $keyFileBase = $sshDir . '\\id_' . $keyType;
        
        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[Windows Provider] Key already exists: {$keyFileBase}\n";
            // Read existing key
            $publicKeyPath = $keyFileBase . '.pub';
            $publicKey = is_readable($publicKeyPath)
                ? trim((string) file_get_contents($publicKeyPath))
                : null;
            
            return new SshEntryEntity(
                id: $systemUsername . ':id_' . $keyType,
                username: $systemUsername,
                name: $label ?? 'Existing key',
                homeDirectory: $usersPath,
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
        
        // Use ssh-keygen (OpenSSH on Windows 10+)
        $cmd = sprintf(
            'ssh-keygen -t %s -f %s -N "" -C %s',
            escapeshellarg($keyType),
            escapeshellarg($keyFileBase),
            escapeshellarg($comment)
        );
        
        echo "[Windows Provider] Executing: {$cmd}\n";
        
        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            echo "[Windows Provider] Error generating key: {$error}\n";
            throw new \RuntimeException(
                "ssh-keygen failed with code {$exitCode}: {$error}"
            );
        }
        
        echo "[Windows Provider] Key generated successfully\n";
        
        $publicKeyPath = $keyFileBase . '.pub';
        $privateKeyPath = $keyFileBase;
        
        $publicKey = is_readable($publicKeyPath)
            ? trim((string) file_get_contents($publicKeyPath))
            : null;
        
        echo "[Windows Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";
        
        $entity = new SshEntryEntity(
            id: $systemUsername . ':id_' . $keyType,
            username: $systemUsername,
            name: $label ?? 'Default key',
            homeDirectory: $usersPath,
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
        echo "[Windows Provider] Syncing SSH configuration\n";
        // On Windows, we could update authorized_keys or SSH config if needed
        // For now, just log
        echo "[Windows Provider] Sync completed\n";
    }
}
