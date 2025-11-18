<?php

namespace ZPMLabs\SshManager\Providers;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Enums\OperatingSystem;

class LinuxSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(SshRepositoryContract $repository)
    {
        parent::__construct($repository);
        echo "[Linux Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::LINUX;
    }

    public function listEntries(?string $ownerId = null): array
    {
        echo "[Linux Provider] Listing entries" . ($ownerId ? " for owner: {$ownerId}" : "") . "\n";
        $entries = parent::listEntries($ownerId);
        echo "[Linux Provider] Found " . count($entries) . " entries\n";
        return $entries;
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Linux Provider] Creating entry for user: {$entry->getUsername()}\n";
        
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
        
        echo "[Linux Provider] Entry created with ID: {$created->getId()}\n";
        echo "[Linux Provider] Public key path: {$created->getPublicKeyPath()}\n";
        echo "[Linux Provider] Private key path: {$created->getPrivateKeyPath()}\n";
        
        $this->sync();
        
        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        echo "[Linux Provider] Updating entry: {$entry->getId()}\n";
        
        $existing = $this->repository->find($entry->getId());
        if (!$existing) {
            throw new \RuntimeException("Entry not found: {$entry->getId()}");
        }
        
        // Update keys if username changed
        if ($existing->getUsername() !== $entry->getUsername()) {
            echo "[Linux Provider] Username changed, regenerating keys\n";
            $this->generateKeyPairForUser(
                $entry->getUsername(),
                $entry->getName() ?? $entry->getComment(),
                'ed25519',
                null
            );
        }
        
        $updated = $this->repository->update($entry);
        
        echo "[Linux Provider] Entry updated: {$updated->getId()}\n";
        
        $this->sync();
        
        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        echo "[Linux Provider] Deleting entry: {$id}\n";
        
        $entry = $this->repository->find($id);
        if ($entry) {
            // Optionally remove keys from system (we'll keep them for safety)
            echo "[Linux Provider] Entry found, removing from repository\n";
            echo "[Linux Provider] Keys kept on system at: {$entry->getPrivateKeyPath()}\n";
        }
        
        $this->repository->delete($id);
        
        echo "[Linux Provider] Entry deleted: {$id}\n";
        
        $this->sync();
    }

    public function sync(): void
    {
        echo "[Linux Provider] Syncing SSH configuration\n";
        // Update authorized_keys or SSH config if needed
        echo "[Linux Provider] Sync completed\n";
    }

    /**
     * Read /etc/passwd and each user's ~/.ssh for keys.
     *
     * @return SshEntryEntity[]
     */
    public function scanSystemUsers(): array
    {
        echo "[Linux Provider] Scanning system users\n";
        $entries = [];

        if (!is_readable('/etc/passwd')) {
            echo "[Linux Provider] /etc/passwd not readable\n";
            return $entries;
        }

        $lines = file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            [$username, , , , , $home, $shell] = explode(':', $line);

            // Skip system / nologin users.
            if ($this->shouldIgnoreShell($shell)) {
                continue;
            }

            $sshDir = rtrim($home, '/') . '/.ssh';

            $pubPath  = $sshDir . '/id_ed25519.pub';
            $privPath = $sshDir . '/id_ed25519';

            $publicKey = is_readable($pubPath)
                ? trim((string) file_get_contents($pubPath))
                : null;

            if ($publicKey) {
                $entries[] = new SshEntryEntity(
                    id: $username . ':id_ed25519',
                    username: $username,
                    name: $username,
                    homeDirectory: $home,
                    publicKeyPath: is_readable($pubPath) ? $pubPath : null,
                    privateKeyPath: is_readable($privPath) ? $privPath : null,
                    publicKey: $publicKey,
                    comment: null,
                    groups: $this->getUserGroups($username),
                    ownerId: null,
                    permissions: [],
                );
            }
        }

        echo "[Linux Provider] Scanned " . count($entries) . " users with SSH keys\n";
        return $entries;
    }

    protected function shouldIgnoreShell(string $shell): bool
    {
        return str_contains($shell, 'nologin')
            || str_contains($shell, 'false');
    }

    /**
     * Resolve groups for given system user.
     *
     * @return string[]
     */
    protected function getUserGroups(string $username): array
    {
        $output = [];
        @exec(sprintf('id -Gn %s 2>/dev/null', escapeshellarg($username)), $output);

        if (empty($output)) {
            return [];
        }

        // id -Gn prints "group1 group2 ..." in single line.
        return preg_split('/\s+/', trim($output[0])) ?: [];
    }

    /**
     * Generate SSH key pair for given system user and store in that user's ~/.ssh.
     */
    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null
    ): SshEntryEntity {
        echo "[Linux Provider] Generating key pair for user: {$systemUsername}\n";
        
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($systemUsername) : null;

        if (!$pw || !isset($pw['dir'])) {
            throw new \RuntimeException("System user '{$systemUsername}' not found.");
        }

        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';

        if (!is_dir($sshDir)) {
            echo "[Linux Provider] Creating .ssh directory: {$sshDir}\n";
            mkdir($sshDir, 0700, true);
        }

        $keyFileBase = $sshDir . '/id_' . $keyType;

        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[Linux Provider] Key already exists: {$keyFileBase}\n";
            // Read existing key
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
                groups: $this->getUserGroups($systemUsername),
                ownerId: null,
                permissions: [],
            );
        }

        $comment = $label ?? ($systemUsername . '@' . gethostname());

        $cmd = [
            'ssh-keygen',
            '-t', $keyType,
            '-f', $keyFileBase,
            '-N', '',             // empty passphrase
            '-C', $comment,
        ];

        if ($bits !== null && $keyType !== 'ed25519') {
            $cmd[] = '-b';
            $cmd[] = (string) $bits;
        }

        $escaped = array_map('escapeshellarg', $cmd);
        $command = implode(' ', $escaped) . ' 2>&1';

        echo "[Linux Provider] Executing: {$command}\n";

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            echo "[Linux Provider] Error generating key: {$error}\n";
            throw new \RuntimeException(
                "ssh-keygen failed with code {$exitCode}: {$error}"
            );
        }

        echo "[Linux Provider] Key generated successfully\n";

        $publicKeyPath  = $keyFileBase . '.pub';
        $privateKeyPath = $keyFileBase;

        $publicKey = is_readable($publicKeyPath)
            ? trim((string) file_get_contents($publicKeyPath))
            : null;

        echo "[Linux Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";

        $entity = new SshEntryEntity(
            id: $systemUsername . ':id_' . $keyType,
            username: $systemUsername,
            name: $label ?? 'Default key',
            homeDirectory: $home,
            publicKeyPath: $publicKeyPath,
            privateKeyPath: $privateKeyPath,
            publicKey: $publicKey,
            comment: $comment,
            groups: $this->getUserGroups($systemUsername),
            ownerId: null,
            permissions: [],
        );

        return $entity;
    }
}
