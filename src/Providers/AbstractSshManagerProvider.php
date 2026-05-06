<?php

namespace ZPMPackages\SshManager\Providers;

use RuntimeException;
use ZPMPackages\SshManager\Contracts\SshManagerContract;
use ZPMPackages\SshManager\Contracts\SshRepositoryContract;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;

abstract class AbstractSshManagerProvider implements SshManagerContract
{
    public function __construct(
        protected SshRepositoryContract $repository,
        protected ?SshManagerCredentialsEntity $managerCredentials = null,
    ) {}

    /**
     * Returns enum for current OS of this provider.
     */
    abstract public function getOs(): OperatingSystem;

    /**
     * @return string[]
     */
    public function listSystemUsernames(): array
    {
        return array_values(array_unique(array_map(
            static fn (SshEntryEntity $entry): string => $entry->getUsername(),
            $this->scanSystemUsers(),
        )));
    }

    public function verifyUserPassword(string $username, string $password): bool
    {
        return false;
    }

    public function updateUserPassword(string $username, string $newPassword): void
    {
        throw new RuntimeException('Updating OS user passwords is not supported for [' . $this->getOsName() . '].');
    }

    /**
     * Helper if we ever need plain string.
     */
    public function getOsName(): string
    {
        return $this->getOs()->value;
    }

    public function getManagerCredentials(): ?SshManagerCredentialsEntity
    {
        return $this->managerCredentials;
    }

    public function hasManagerCredentials(): bool
    {
        return $this->managerCredentials !== null;
    }

        protected function hasRemoteHost(): bool
        {
            return $this->managerCredentials?->hasRemoteHost() ?? false;
        }

    /**
     * @return array{output: string[], exitCode: int}
     */
    protected function executeCommand(array $command): array
    {
        $output = [];
        $exitCode = 0;

        exec($this->buildExecutionCommand($command), $output, $exitCode);

        return [
            'output' => $output,
            'exitCode' => $exitCode,
        ];
    }

    protected function buildExecutionCommand(array $command): string
    {
        $baseCommand = $this->buildBaseCommand($command);

            if ($this->hasRemoteHost()) {
                return $this->buildRemoteCommand($baseCommand);
            }

        if (! $this->hasManagerCredentials()) {
            return $baseCommand . ' 2>&1';
        }

        return match ($this->getOs()) {
            OperatingSystem::WINDOWS => $this->buildWindowsManagerCommand($command),
            OperatingSystem::LINUX,
            OperatingSystem::MACOS,
            OperatingSystem::ANDROID => $this->buildUnixManagerCommand($baseCommand),
        };
    }

    protected function createDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! $this->hasManagerCredentials()) {
            mkdir($path, 0700, true);

            return;
        }

        $command = match ($this->getOs()) {
            OperatingSystem::WINDOWS => [
                'powershell',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                "New-Item -ItemType Directory -Path '" . $this->escapePowerShellLiteral($path) . "' -Force | Out-Null",
            ],
            default => ['mkdir', '-p', $path],
        };

        $result = $this->executeCommand($command);

        if ($result['exitCode'] !== 0 && ! is_dir($path)) {
            throw new RuntimeException('Unable to create directory [' . $path . '].');
        }
    }

    protected function readFileContents(string $path): ?string
    {
        if (is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        if (! $this->hasManagerCredentials() || $this->getOs() === OperatingSystem::WINDOWS) {
            return null;
        }

        $result = $this->executeCommand(['cat', $path]);

        if ($result['exitCode'] !== 0) {
            return null;
        }

        return trim(implode(PHP_EOL, $result['output']));
    }

    protected function writeFileContents(string $path, string $contents): void
    {
        $this->createDirectory(dirname($path));

        if (! $this->hasManagerCredentials()) {
            $bytesWritten = @file_put_contents($path, $contents);

            if ($bytesWritten !== false) {
                return;
            }
        }

        $encodedContents = base64_encode($contents);

        $command = match ($this->getOs()) {
            OperatingSystem::WINDOWS => [
                'powershell',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                "[System.IO.File]::WriteAllText('" . $this->escapePowerShellLiteral($path) . "', [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('" . $this->escapePowerShellLiteral($encodedContents) . "')))",
            ],
            default => [
                'sh',
                '-lc',
                'printf %s ' . escapeshellarg($encodedContents) . ' | base64 -d > ' . escapeshellarg($path),
            ],
        };

        $result = $this->executeCommand($command);

        $this->ensureCommandSucceeded($result, 'write file [' . $path . ']');
    }

    protected function ensureCommandSucceeded(array $result, string $context): void
    {
        if ($result['exitCode'] === 0) {
            return;
        }

        throw new RuntimeException(
            $context . ' failed with code ' . $result['exitCode'] . ': ' . implode(PHP_EOL, $result['output'])
        );
    }

    protected function buildBaseCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    protected function buildUnixManagerCommand(string $baseCommand): string
    {
        $credentials = $this->getManagerCredentials();

            if ($credentials === null || ! $credentials->hasPassword()) {
            return $baseCommand . ' 2>&1';
        }

        return sprintf(
            "printf '%%s\\n' %s | su - %s -c %s 2>&1",
            escapeshellarg($credentials->getPassword()),
            escapeshellarg($credentials->getUsername()),
            escapeshellarg($baseCommand),
        );
    }

    protected function buildWindowsManagerCommand(array $command): string
    {
        $credentials = $this->getManagerCredentials();

            if ($credentials === null || ! $credentials->hasPassword()) {
            return $this->buildBaseCommand($command) . ' 2>&1';
        }

        $binary = array_shift($command);

        if ($binary === null) {
            throw new RuntimeException('Command binary is required.');
        }

        $arguments = implode(', ', array_map(
            fn (string $argument): string => "'" . $this->escapePowerShellLiteral($argument) . "'",
            $command,
        ));

        $script = sprintf(
            "\$password = ConvertTo-SecureString '%s' -AsPlainText -Force; " .
            "\$credential = New-Object System.Management.Automation.PSCredential('%s', \$password); " .
            "\$process = Start-Process -FilePath '%s' -ArgumentList @(%s) -Credential \$credential -Wait -PassThru -WindowStyle Hidden; " .
            'exit $process.ExitCode',
            $this->escapePowerShellLiteral($credentials->getPassword()),
            $this->escapePowerShellLiteral($credentials->getUsername()),
            $this->escapePowerShellLiteral($binary),
            $arguments,
        );

        return 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . str_replace('"', '`"', $script) . '" 2>&1';
    }

    protected function escapePowerShellLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

        protected function buildRemoteCommand(string $baseCommand): string
        {
            $credentials = $this->getManagerCredentials();

            if ($credentials === null || ! $credentials->hasRemoteHost()) {
                return $baseCommand . ' 2>&1';
            }

            $target = $credentials->getUsername() . '@' . $credentials->getHost();
            $port = $credentials->getPort() ?? 22;

            return sprintf(
                'ssh -o StrictHostKeyChecking=no -p %d %s %s 2>&1',
                $port,
                escapeshellarg($target),
                escapeshellarg($baseCommand),
            );
        }

    protected function extractPublicKeyComment(?string $publicKey): ?string
    {
        if ($publicKey === null) {
            return null;
        }

        $segments = preg_split('/\s+/', trim($publicKey), 3);

        if (! is_array($segments) || count($segments) < 3) {
            return null;
        }

        return $segments[2] !== '' ? $segments[2] : null;
    }

    protected function normalizeKeyType(?string $keyType): string
    {
        $keyType = strtolower(trim((string) ($keyType ?? '')));

        return $keyType !== '' ? $keyType : 'ed25519';
    }

    protected function resolveManagedKeyFileBase(string $sshDirectory, ?string $label, string $keyType, string $directorySeparator): string
    {
        $fileName = $this->sanitizeManagedKeyFileName($label);

        if ($fileName === null) {
            $fileName = 'id_' . $keyType;
        }

        return rtrim($sshDirectory, '\\/') . $directorySeparator . $fileName;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveManagedKeyPaths(
        string $homeDirectory,
        ?string $label,
        string $keyType,
        string $directorySeparator,
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
    ): array {
        $sshDirectory = rtrim($homeDirectory, '\\/') . $directorySeparator . '.ssh';

        if ($publicKeyPath === null && $privateKeyPath === null) {
            $resolvedPrivateKeyPath = $this->resolveManagedKeyFileBase($sshDirectory, $label, $keyType, $directorySeparator);

            return [$resolvedPrivateKeyPath, $resolvedPrivateKeyPath . '.pub'];
        }

        $resolvedPrivateKeyPath = $privateKeyPath;

        if ($resolvedPrivateKeyPath === null && is_string($publicKeyPath)) {
            $resolvedPrivateKeyPath = str_ends_with($publicKeyPath, '.pub')
                ? substr($publicKeyPath, 0, -4)
                : $publicKeyPath;
        }

        if (! is_string($resolvedPrivateKeyPath) || trim($resolvedPrivateKeyPath) === '') {
            throw new RuntimeException('A private key path is required when providing a custom key path.');
        }

        $resolvedPrivateKeyPath = $this->normalizeManagedKeyPath($resolvedPrivateKeyPath, $sshDirectory, $directorySeparator);
        $resolvedPublicKeyPath = $publicKeyPath !== null
            ? $this->normalizeManagedKeyPath($publicKeyPath, $sshDirectory, $directorySeparator)
            : $resolvedPrivateKeyPath . '.pub';

        if ($resolvedPublicKeyPath !== $resolvedPrivateKeyPath . '.pub') {
            throw new RuntimeException('The public key path must match the private key path with a .pub suffix and remain inside the user .ssh directory.');
        }

        return [$resolvedPrivateKeyPath, $resolvedPublicKeyPath];
    }

    protected function sanitizeManagedKeyFileName(?string $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $normalized = strtolower(trim($label));

        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '.-_ ');

        return $normalized !== '' ? $normalized : null;
    }

    protected function normalizeManagedKeyPath(string $path, string $sshDirectory, string $directorySeparator): string
    {
        $normalizedPath = str_replace(['/', '\\'], $directorySeparator, trim($path));

        if ($normalizedPath === '') {
            throw new RuntimeException('Custom key paths cannot be empty.');
        }

        if (! $this->isAbsoluteManagedKeyPath($normalizedPath, $directorySeparator)) {
            $normalizedPath = rtrim($sshDirectory, '\\/') . $directorySeparator . ltrim($normalizedPath, '\\/');
        }

        $normalizedSshDirectory = $this->normalizePathForComparison($sshDirectory, $directorySeparator);
        $normalizedCandidate = $this->normalizePathForComparison($normalizedPath, $directorySeparator);

        if ($normalizedCandidate !== $normalizedSshDirectory && ! str_starts_with($normalizedCandidate, $normalizedSshDirectory . $directorySeparator)) {
            throw new RuntimeException('Custom key paths must stay inside the target user .ssh directory.');
        }

        return $normalizedPath;
    }

    protected function normalizePathForComparison(string $path, string $directorySeparator): string
    {
        $normalized = str_replace(['/', '\\'], $directorySeparator, trim($path));
        $normalized = rtrim($normalized, '\\/');

        return $this->getOs() === OperatingSystem::WINDOWS
            ? strtolower($normalized)
            : $normalized;
    }

    protected function isAbsoluteManagedKeyPath(string $path, string $directorySeparator): bool
    {
        if ($directorySeparator === '\\') {
            return preg_match('/^[a-zA-Z]:\\\\/', $path) === 1 || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }

    /**
     * @param  string[]  $authorizedKeys
     * @return string[]
     */
    protected function normalizeAuthorizedKeys(array $authorizedKeys): array
    {
        $authorizedKeys = array_map(
            static fn (string $authorizedKey): string => trim($authorizedKey),
            $authorizedKeys,
        );

        return array_values(array_unique(array_filter($authorizedKeys)));
    }

    protected function buildAuthorizedKeysPath(?string $homeDirectory): ?string
    {
        if ($homeDirectory === null || trim($homeDirectory) === '') {
            return null;
        }

        $separator = $this->getOs() === OperatingSystem::WINDOWS ? '\\' : '/';

        return rtrim($homeDirectory, '\\/') . $separator . '.ssh' . $separator . 'authorized_keys';
    }

    protected function buildSshConfigPath(?string $homeDirectory): ?string
    {
        if ($homeDirectory === null || trim($homeDirectory) === '') {
            return null;
        }

        $separator = $this->getOs() === OperatingSystem::WINDOWS ? '\\' : '/';

        return rtrim($homeDirectory, '\\/') . $separator . '.ssh' . $separator . 'config';
    }

    /**
     * @return string[]
     */
    public function readAuthorizedKeysForHomeDirectory(?string $homeDirectory): array
    {
        return $this->loadAuthorizedKeys($homeDirectory);
    }

    /**
     * @param  string[]  $authorizedKeys
     */
    public function writeAuthorizedKeysForHomeDirectory(?string $homeDirectory, array $authorizedKeys): void
    {
        $authorizedKeysPath = $this->buildAuthorizedKeysPath($homeDirectory);

        if ($authorizedKeysPath === null) {
            return;
        }

        $normalizedAuthorizedKeys = $this->normalizeAuthorizedKeys($authorizedKeys);
        $contents = $normalizedAuthorizedKeys === []
            ? ''
            : implode(PHP_EOL, $normalizedAuthorizedKeys) . PHP_EOL;

        $this->writeFileContents($authorizedKeysPath, $contents);
    }

    public function readSshConfigForHomeDirectory(?string $homeDirectory): string
    {
        $sshConfigPath = $this->buildSshConfigPath($homeDirectory);

        if ($sshConfigPath === null) {
            return '';
        }

        return $this->readFileContents($sshConfigPath) ?? '';
    }

    public function writeSshConfigForHomeDirectory(?string $homeDirectory, ?string $contents): void
    {
        $sshConfigPath = $this->buildSshConfigPath($homeDirectory);

        if ($sshConfigPath === null) {
            return;
        }

        $contents = trim((string) $contents);

        $this->writeFileContents(
            $sshConfigPath,
            $contents === '' ? '' : rtrim($contents) . PHP_EOL,
        );
    }

    /**
     * @return string[]
     */
    protected function loadAuthorizedKeys(?string $homeDirectory): array
    {
        $authorizedKeysPath = $this->buildAuthorizedKeysPath($homeDirectory);

        if ($authorizedKeysPath === null) {
            return [];
        }

        $contents = $this->readFileContents($authorizedKeysPath);

        if ($contents === null || trim($contents) === '') {
            return [];
        }

        $authorizedKeys = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        return $this->normalizeAuthorizedKeys($authorizedKeys);
    }

    protected function shouldRegenerateKeyMaterial(SshEntryEntity $existing, SshEntryEntity $entry): bool
    {
        return ($existing->getUsername() !== $entry->getUsername())
            || ($this->normalizeKeyType($existing->getKeyType()) !== $this->normalizeKeyType($entry->getKeyType()))
            || ($existing->getKeyBits() !== $entry->getKeyBits());
    }

    protected function buildManagedEntryFromGeneratedKey(SshEntryEntity $entry, SshEntryEntity $generatedKey): SshEntryEntity
    {
        return new SshEntryEntity(
            id: $entry->getId() !== '' ? $entry->getId() : $generatedKey->getId(),
            username: $entry->getUsername(),
            name: $entry->getName() ?? $generatedKey->getName(),
            homeDirectory: $generatedKey->getHomeDirectory(),
            publicKeyPath: $generatedKey->getPublicKeyPath(),
            privateKeyPath: $generatedKey->getPrivateKeyPath(),
            publicKey: $generatedKey->getPublicKey(),
            authorizedKeys: $this->normalizeAuthorizedKeys(
                $entry->getAuthorizedKeys() !== []
                    ? $entry->getAuthorizedKeys()
                    : $generatedKey->getAuthorizedKeys(),
            ),
            comment: $entry->getComment() ?? $generatedKey->getComment(),
            groups: $entry->getGroups() !== [] ? $entry->getGroups() : $generatedKey->getGroups(),
            ownerId: $entry->getOwnerId(),
            keyType: $this->normalizeKeyType($entry->getKeyType() ?? $generatedKey->getKeyType()),
            keyBits: $entry->getKeyBits() ?? $generatedKey->getKeyBits(),
            canReadEntries: $entry->canReadEntries(),
            canWriteEntries: $entry->canWriteEntries(),
            canManageEntries: $entry->canManageEntries(),
            managedDirectories: $entry->getManagedDirectories(),
            permissions: $entry->getPermissions(),
        );
    }

    protected function syncAuthorizedKeysEntries(): void
    {
        foreach ($this->repository->all() as $entry) {
            $this->syncAuthorizedKeysForEntry($entry);
        }
    }

    protected function syncAuthorizedKeysForEntry(SshEntryEntity $entry): void
    {
        $authorizedKeysPath = $this->buildAuthorizedKeysPath($entry->getHomeDirectory());

        if ($authorizedKeysPath === null) {
            return;
        }

        $authorizedKeys = $this->normalizeAuthorizedKeys($entry->getAuthorizedKeys());

        if ($authorizedKeys === [] && $entry->getPublicKey() !== null) {
            $authorizedKeys = [$entry->getPublicKey()];
        }

        $contents = $authorizedKeys === []
            ? ''
            : implode(PHP_EOL, $authorizedKeys) . PHP_EOL;

        $this->writeFileContents($authorizedKeysPath, $contents);
    }

    public function listEntries(?string $ownerId = null): array
    {
        return $this->repository->all($ownerId);
    }

    public function findEntry(string $id): ?SshEntryEntity
    {
        return $this->repository->find($id);
    }

    public function createEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $created = $this->repository->create($entry);

        $this->sync();

        return $created;
    }

    public function updateEntry(SshEntryEntity $entry): SshEntryEntity
    {
        $updated = $this->repository->update($entry);

        $this->sync();

        return $updated;
    }

    public function deleteEntry(string $id): void
    {
        $this->repository->delete($id);

        $this->sync();
    }

    /**
     * OS-specific sync: write authorized_keys, config files, etc.
     * Providers can override; by default we do nothing.
     */
    public function sync(): void
    {
        // Default no-op; OS providers override if needed.
    }

    /**
     * Must be implemented per OS – scanning real system users / keys.
     *
     * @return SshEntryEntity[]
     */
    abstract public function scanSystemUsers(): array;

    /**
     * Must be implemented per OS – generate SSH key pair for given system user.
     */
    abstract public function generateKeyPairForUser(
        string $systemUsername,
        ?string $label = null,
        ?string $keyType = 'ed25519',
        ?int $bits = null,
        ?string $passphrase = null,
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
    ): SshEntryEntity;
}
