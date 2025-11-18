<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Enums\OperatingSystem;

class MacOsSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(SshRepositoryContract $repository)
    {
        parent::__construct($repository);
        echo "[macOS Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::MACOS;
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
        
        // Update keys if username changed
        if ($existing->getUsername() !== $entry->getUsername()) {
            echo "[macOS Provider] Username changed, regenerating keys\n";
            $this->generateKeyPairForUser(
                $entry->getUsername(),
                $entry->getName() ?? $entry->getComment(),
                'ed25519',
                null
            );
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
        
        // macOS uses dscl for user management, similar to Linux for SSH keys
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam(get_current_user()) : null;
        if (!$pw) {
            echo "[macOS Provider] Could not get current user\n";
            return $entries;
        }
        
        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        if (is_dir($sshDir)) {
            $pubPath = $sshDir . '/id_ed25519.pub';
            $privPath = $sshDir . '/id_ed25519';
            
            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;
            
            if ($publicKey) {
                $entries[] = new SshEntryEntity(
                    id: get_current_user() . ':id_ed25519',
                    username: get_current_user(),
                    name: get_current_user(),
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
        
        echo "[macOS Provider] Scanned " . count($entries) . " users with SSH keys\n";
        return $entries;
    }

    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        echo "[macOS Provider] Generating key pair for user: {$systemUsername}\n";
        
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($systemUsername) : null;
        
        if (!$pw || !isset($pw['dir'])) {
            throw new \RuntimeException("System user '{$systemUsername}' not found.");
        }
        
        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';
        
        if (!is_dir($sshDir)) {
            echo "[macOS Provider] Creating .ssh directory: {$sshDir}\n";
            mkdir($sshDir, 0700, true);
        }
        
        $keyFileBase = $sshDir . '/id_' . $keyType;
        
        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[macOS Provider] Key already exists: {$keyFileBase}\n";
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
        
        echo "[macOS Provider] Executing: {$command}\n";
        
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        
        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            echo "[macOS Provider] Error generating key: {$error}\n";
            throw new \RuntimeException(
                "ssh-keygen failed with code {$exitCode}: {$error}"
            );
        }
        
        echo "[macOS Provider] Key generated successfully\n";
        
        $publicKeyPath = $keyFileBase . '.pub';
        $privateKeyPath = $keyFileBase;
        
        $publicKey = is_readable($publicKeyPath)
            ? trim((string) file_get_contents($publicKeyPath))
            : null;
        
        echo "[macOS Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";
        
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
        echo "[macOS Provider] Syncing SSH configuration\n";
        echo "[macOS Provider] Sync completed\n";
    }
}
