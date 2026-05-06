<?php

namespace ZPMPackages\SshManager\Providers;

use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;

class LinuxSshManagerProvider extends AbstractSshManagerProvider
{
    public function __construct(
        SshRepositoryContract $repository,
        ?SshManagerCredentialsEntity $managerCredentials = null,
    )
    {
        parent::__construct($repository, $managerCredentials);
        echo "[Linux Provider] Initialized\n";
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::LINUX;
    }

    public function listSystemUsernames(): array
    {
        if (! is_readable('/etc/passwd')) {
            return [];
        }

        $lines = file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $usernames = [];

        foreach ($lines as $line) {
            $segments = explode(':', $line);

            if (! isset($segments[0], $segments[6])) {
                continue;
            }

            if ($this->shouldIgnoreShell($segments[6])) {
                continue;
            }

            $usernames[] = $segments[0];
        }

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
        $encoded = base64_encode($username . ':' . $newPassword);

        $result = $this->executeCommand([
            'sh',
            '-lc',
            'printf %s ' . escapeshellarg($encoded) . ' | base64 -d | chpasswd',
        ]);

        $this->ensureCommandSucceeded($result, 'update Linux user password [' . $username . ']');
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

        $entry = $this->syncSystemUser($existing, $entry);
        
        if ($this->shouldRegenerateKeyMaterial($existing, $entry)) {
            echo "[Linux Provider] Username changed, regenerating keys\n";
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
        
        echo "[Linux Provider] Entry updated: {$updated->getId()}\n";
        
        $this->sync();
        
        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        echo "[Linux Provider] Deleting entry: {$id}\n";
        
        $entry = $this->repository->find($id);
        if ($entry) {
            $this->deleteSystemUser($entry->getUsername());
        }
        
        $this->repository->delete($id);
        
        echo "[Linux Provider] Entry deleted: {$id}\n";
        
        $this->sync();
    }

    public function sync(): void
    {
        echo "[Linux Provider] Syncing SSH configuration\n";
        $this->syncAuthorizedKeysEntries();
        echo "[Linux Provider] Sync completed\n";
    }

    protected function ensureSystemUserExists(SshEntryEntity $entry): SshEntryEntity
    {
        if (! $this->systemUserExists($entry->getUsername())) {
            $homeDirectory = $entry->getHomeDirectory() ?? ('/home/' . $entry->getUsername());
            $command = ['useradd', '-m', '-d', $homeDirectory];

            if (($entry->getName() !== null) && ($entry->getName() !== '')) {
                $command[] = '-c';
                $command[] = $entry->getName();
            }

            $command[] = $entry->getUsername();

            $result = $this->executeCommand($command);

            $this->ensureCommandSucceeded($result, 'create Linux user [' . $entry->getUsername() . ']');
        }

        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($entry->getUsername()) : null;
        $homeDirectory = (isset($pw['dir']) && is_string($pw['dir']))
            ? $pw['dir']
            : ($entry->getHomeDirectory() ?? ('/home/' . $entry->getUsername()));

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

        if ($existing->getUsername() !== $entry->getUsername()) {
            $renameResult = $this->executeCommand(['usermod', '-l', $entry->getUsername(), $existing->getUsername()]);
            $this->ensureCommandSucceeded($renameResult, 'rename Linux user [' . $existing->getUsername() . ']');
        }

        if (($entry->getHomeDirectory() !== null) && ($entry->getHomeDirectory() !== '')) {
            $homeResult = $this->executeCommand(['usermod', '-d', (string) $entry->getHomeDirectory(), '-m', $entry->getUsername()]);
            $this->ensureCommandSucceeded($homeResult, 'update Linux home directory [' . $entry->getUsername() . ']');
        }

        if (($entry->getName() !== null) && ($entry->getName() !== '')) {
            $commentResult = $this->executeCommand(['usermod', '-c', (string) $entry->getName(), $entry->getUsername()]);
            $this->ensureCommandSucceeded($commentResult, 'update Linux user comment [' . $entry->getUsername() . ']');
        }

        return $this->ensureSystemUserExists($entry);
    }

    protected function deleteSystemUser(string $username): void
    {
        if ((! $this->hasManagerCredentials()) || (! $this->systemUserExists($username))) {
            return;
        }

        $result = $this->executeCommand(['userdel', '-r', $username]);

        $this->ensureCommandSucceeded($result, 'delete Linux user [' . $username . ']');
    }

    protected function systemUserExists(string $username): bool
    {
        $result = $this->executeCommand(['id', $username]);

        return $result['exitCode'] === 0;
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

        if (! is_readable('/etc/passwd')) {
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
                groups: $this->getUserGroups($username),
                ownerId: null,
                keyType: $publicKey ? 'ed25519' : null,
                keyBits: null,
                permissions: [],
            );
        }

        echo "[Linux Provider] Scanned " . count($entries) . " users\n";
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
        $result = $this->executeCommand(['id', '-Gn', $username]);

        if ($result['exitCode'] !== 0 || empty($result['output'])) {
            return [];
        }

        // id -Gn prints "group1 group2 ..." in single line.
        return preg_split('/\s+/', trim($result['output'][0])) ?: [];
    }

    /**
     * Generate SSH key pair for given system user and store in that user's ~/.ssh.
     */
    public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null,
        ?string $passphrase = null,
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
    ): SshEntryEntity {
        echo "[Linux Provider] Generating key pair for user: {$systemUsername}\n";

        $keyType = $this->normalizeKeyType($keyType);
        
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($systemUsername) : null;

        if (!$pw || !isset($pw['dir'])) {
            throw new \RuntimeException("System user '{$systemUsername}' not found.");
        }

        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';

        if (!is_dir($sshDir)) {
            echo "[Linux Provider] Creating .ssh directory: {$sshDir}\n";
            $this->createDirectory($sshDir);
        }

        [$keyFileBase, $publicKeyPath] = $this->resolveManagedKeyPaths($home, $label, $keyType, '/', $publicKeyPath, $privateKeyPath);

        // Check if key already exists
        if (file_exists($keyFileBase)) {
            echo "[Linux Provider] Key already exists: {$keyFileBase}\n";
            // Read existing key
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
                groups: $this->getUserGroups($systemUsername),
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

        echo "[Linux Provider] Executing: {$command}\n";

        $result = $this->executeCommand($cmd);

        if ($result['exitCode'] !== 0) {
            $error = implode("\n", $result['output']);
            echo "[Linux Provider] Error generating key: {$error}\n";
            $this->ensureCommandSucceeded($result, 'ssh-keygen');
        }

        echo "[Linux Provider] Key generated successfully\n";

        $privateKeyPath = $keyFileBase;

        $publicKey = $this->readFileContents($publicKeyPath);

        echo "[Linux Provider] Public key: " . substr($publicKey ?? '', 0, 50) . "...\n";

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
            groups: $this->getUserGroups($systemUsername),
            ownerId: null,
            keyType: $keyType,
            keyBits: $bits,
            permissions: [],
        );

        return $entity;
    }
}
