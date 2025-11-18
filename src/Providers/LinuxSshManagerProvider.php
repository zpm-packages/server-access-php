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
    }

    public function getOs(): OperatingSystem
    {
        return OperatingSystem::LINUX;
    }

    /**
     * Read /etc/passwd and each user's ~/.ssh for keys.
     *
     * @return SshEntryEntity[]
     */
    public function scanSystemUsers(): array
    {
        $entries = [];

        if (!is_readable('/etc/passwd')) {
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
                id: $username,                // or uuid if želiš
                username: $username,
                name: $username,
                homeDirectory: $home,
                publicKeyPath: is_readable($pubPath) ? $pubPath : null,
                privateKeyPath: is_readable($privPath) ? $privPath : null,
                publicKey: $publicKey,
                comment: null,
                groups: $this->getUserGroups($username),
                ownerId: null,
                permissions: [], // app-level permissions, ne OS
            );
        }

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
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($systemUsername) : null;

        if (!$pw || !isset($pw['dir'])) {
            throw new \RuntimeException("System user '{$systemUsername}' not found.");
        }

        $home = $pw['dir'];
        $sshDir = rtrim($home, '/') . '/.ssh';

        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }

        $keyFileBase = $sshDir . '/id_' . $keyType;

        // Avoid overwriting existing key.
        if (file_exists($keyFileBase)) {
            throw new \RuntimeException("Key file {$keyFileBase} already exists.");
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

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "ssh-keygen failed with code {$exitCode}: " . implode("\n", $output)
            );
        }

        $publicKeyPath  = $keyFileBase . '.pub';
        $privateKeyPath = $keyFileBase;

        $publicKey = is_readable($publicKeyPath)
            ? trim((string) file_get_contents($publicKeyPath))
            : null;

        $entity = new SshEntryEntity(
            id: $systemUsername . ':' . basename($keyFileBase),
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

        $this->repository->create($entity);

        $this->sync(); // npr. update authorized_keys

        return $entity;
    }
}
