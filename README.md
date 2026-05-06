# ZPMPackages Server Access

Cross-platform PHP primitives for managing SSH-backed server access, users, keys, permissions, and OS-level synchronization.

This package is **not** a full interactive SSH client library like phpseclib or OpenSSH bindings.  
Its purpose is to:

- keep a structured list of SSH entries (users + keys + permissions) via a repository
- scan the current OS for SSH users and keys
- generate SSH key pairs for system users
- execute management commands through configured manager credentials on local or remote hosts
- optionally sync repository state with OS-level config (e.g. `authorized_keys` – per OS provider)

In practice, it acts as a server-access management layer. It can target the current machine or a remote host by wrapping system commands with configured manager credentials, but it is not designed to be a general-purpose shell, terminal session, SFTP, or port-forwarding client.

The public PHP namespace is `ZPMPackages\SshManager\...`.

---

## Installation

```bash
composer require zpm-packages/server-access
```

Requires **PHP 8.2+**.

---

## Basic Concepts

- `SshEntryEntity`  
  Represents one SSH entry (user + key paths + groups + permissions).

- `SshRepositoryContract`  
  Abstraction for storing entries (in memory, JSON, DB …).

- `SshManagerContract`  
  High-level API for:
  - listing entries
  - create / update / delete
  - scanning system users / keys
  - generating key pairs for system users

- `SshManagerFactory`  
  Creates an OS-specific `SshManagerContract` implementation (Linux / Windows / macOS / Android).

---

## Quick Start

### 1. Create a Repository

For testing and simple usage there is an in-memory implementation:

```php
use ZPMPackages\SshManager\Repositories\InMemorySshRepository;

$repository = new InMemorySshRepository();
```

Later, you can replace this with your own implementation of
`ZPMPackages\SshManager\Contracts\SshRepositoryContract` (e.g. DB / JSON file).

---

### 2. Create the Manager via Factory

```php
use ZPMPackages\SshManager\Factories\SshManagerFactory;

// Detect OS automatically and create a manager for it:
$manager = SshManagerFactory::make($repository);
```

`SshManagerFactory` internally uses `SystemDetectorService` + `OperatingSystem` enum
to choose the correct provider:

- Linux → `LinuxSshManagerProvider`
- Windows → `WindowsSshManagerProvider`
- macOS → `MacOsSshManagerProvider`
- Android → `AndroidSshManagerProvider`

---

## Listing Entries

There are **two** ways to list entries:

1. From your **repository** (`listEntries`) – what *your app* stores.
2. From the **actual OS** (`scanSystemUsers`) – what the OS currently has.

### 1) List all stored entries (from repository)

```php
use ZPMPackages\SshManager\Entities\SshEntryEntity;

/** @var \ZPMPackages\SshManager\Contracts\SshManagerContract $manager */

// All entries, regardless of owner:
$entries = $manager->listEntries();

foreach ($entries as $entry) {
    /** @var SshEntryEntity $entry */
    echo $entry->getId() . PHP_EOL;
    echo 'User: ' . $entry->getUsername() . PHP_EOL;
    echo 'Home: ' . $entry->getHomeDirectory() . PHP_EOL;
    echo 'Public key path: ' . $entry->getPublicKeyPath() . PHP_EOL;
    echo 'Groups: ' . implode(', ', $entry->getGroups()) . PHP_EOL;
    echo str_repeat('-', 40) . PHP_EOL;
}
```

### 2) List entries for a specific owner (app-level user)

```php
$ownerId = 'user-123';

$entriesForOwner = $manager->listEntries($ownerId);
```

### 3) Scan system users / keys (OS-level, read-only)

```php
$systemEntries = $manager->scanSystemUsers();

foreach ($systemEntries as $entry) {
    echo $entry->getUsername() . PHP_EOL;
    echo 'Home: ' . $entry->getHomeDirectory() . PHP_EOL;
    echo 'Public key path: ' . $entry->getPublicKeyPath() . PHP_EOL;
    echo 'Groups: ' . implode(', ', $entry->getGroups()) . PHP_EOL;
    echo str_repeat('=', 40) . PHP_EOL;
}
```

> `scanSystemUsers()` does **not** modify your repository.  
> It just inspects the current OS state (for Linux provider it reads `/etc/passwd` and `~/.ssh`).

---

## Creating Entries

You can create entries manually and store them in the repository via the manager.

```php
use ZPMPackages\SshManager\Entities\SshEntryEntity;

$entry = new SshEntryEntity(
    id: 'entry-1',
    username: 'deploy',
    name: 'Deploy key',
    homeDirectory: '/home/deploy',
    publicKeyPath: '/home/deploy/.ssh/id_ed25519.pub',
    privateKeyPath: '/home/deploy/.ssh/id_ed25519',
    publicKey: null,          // optional – can be filled with file contents
    comment: 'Deployment key',
    groups: ['deploy', 'www-data'],
    ownerId: 'user-123',
    permissions: [],          // app-level permissions (see SshPermissionEntity)
);

// This writes to repository and triggers OS sync (if provider implements it).
$created = $manager->createEntry($entry);

// $created is the same entity, potentially modified by repository implementation.
```

---

## Updating Entries

To update an existing entry:

```php
/** @var \ZPMPackages\SshManager\Entities\SshEntryEntity|null $existing */
$existing = $manager->findEntry('entry-1');

if ($existing !== null) {
    $updatedEntry = new SshEntryEntity(
        id: $existing->getId(),
        username: $existing->getUsername(),
        name: 'Updated deploy key',
        homeDirectory: $existing->getHomeDirectory(),
        publicKeyPath: $existing->getPublicKeyPath(),
        privateKeyPath: $existing->getPrivateKeyPath(),
        publicKey: $existing->getPublicKey(),
        comment: 'Updated comment',
        groups: $existing->getGroups(),
        ownerId: $existing->getOwnerId(),
        permissions: $existing->getPermissions(),
    );

    $saved = $manager->updateEntry($updatedEntry);
}
```

`updateEntry()`:

- persists the updated entity in the repository
- calls `sync()` on the provider (if implemented), so OS config can be refreshed.

---

## Deleting Entries

To delete by ID:

```php
$manager->deleteEntry('entry-1');
```

This:

1. Removes the entry from the repository.
2. Calls `sync()` on the provider, so OS-level configuration can be adjusted.

---

## Generating SSH Key Pair for a System User

On Linux, `LinuxSshManagerProvider` provides a real implementation using `ssh-keygen`.
On other OS providers it is currently a TODO (they throw a `RuntimeException`).

```php
// This will:
// 1) Resolve the system user's home dir.
// 2) Create ~/.ssh if it doesn't exist.
// 3) Run ssh-keygen and create files (e.g. id_ed25519 and id_ed25519.pub).
// 4) Create a SshEntryEntity for this key.
// 5) Store it in the repository.
// 6) Call sync() on the provider.
$newEntry = $manager->generateKeyPairForUser(
    systemUsername: 'deploy',
    label: 'deploy@my-server',  // comment in the key
    keyType: 'ed25519',         // or 'rsa'
    bits: null                  // for rsa you can pass e.g. 4096
);

echo $newEntry->getUsername() . PHP_EOL;
echo $newEntry->getPublicKeyPath() . PHP_EOL;
echo $newEntry->getPrivateKeyPath() . PHP_EOL;
```

---

## Finding a Single Entry

```php
$entry = $manager->findEntry('entry-1');

if ($entry !== null) {
    echo $entry->getUsername() . PHP_EOL;
}
```

---

## Extending the Package

You can extend the package in several ways:

- Implement your own `SshRepositoryContract`:
  - JSON file repository
  - Database-backed repository (e.g. Doctrine / Eloquent / custom)
- Implement OS-specific sync logic in providers:
  - generate `authorized_keys` files
  - update custom SSH config templates per entry
- Add additional providers for specific environments or containers.

---

## Framework Integrations

This package stays framework-agnostic on purpose. If you want container bindings, Laravel wrappers, Eloquent-backed repositories, sync commands, or a Filament admin UI, use the higher-level packages instead of duplicating that setup here.

- Laravel integration package: `zpm-packages/server-access-laravel`
- Filament admin package: `zpm-packages/server-access-filament`

Recommended wrapper structure in an application:

- bind `ZPMPackages\SshManager\Contracts\SshRepositoryContract` in your app or package
- resolve the manager through a wrapper or provider around `SshManagerFactory`
- keep app-specific database models, policies, and UI concerns outside this core package

See the package READMEs for those layers:

- Laravel wrapper: `../laravel-ssh-management/README.md`
- Filament wrapper: `../filament-ssh-management/README.md`

---

## Notes

- All providers support the core entry management flow, while OS-level behavior depends on the active provider.
- When creating entries, SSH keys are automatically generated on the system.
- All code and comments are kept framework-agnostic. You can build Laravel, Symfony, or custom application layers on top of this package.
- Higher-level container bindings, persistence wrappers, and admin UI integrations belong in the Laravel and Filament packages, not in this core package.
