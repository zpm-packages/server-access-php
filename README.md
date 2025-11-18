# ZPMLabs SSH Manager

Simple, OS-aware SSH configuration manager for PHP.

This package **does not** act as an SSH client.  
Its purpose is to:

- keep a structured list of SSH entries (users + keys + permissions) via a repository
- scan the current OS for SSH users and keys
- generate SSH key pairs for system users
- optionally sync repository state with OS-level config (e.g. `authorized_keys` – per OS provider)

---

## Installation

```bash
composer require zpmlabs/ssh-manager
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
use ZPMLabs\SshManager\Repositories\InMemorySshRepository;

$repository = new InMemorySshRepository();
```

Later, you can replace this with your own implementation of
`ZPMLabs\SshManager\Contracts\SshRepositoryContract` (e.g. DB / JSON file).

---

### 2. Create the Manager via Factory

```php
use ZPMLabs\SshManager\Factories\SshManagerFactory;

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
use ZPMLabs\SshManager\Entities\SshEntryEntity;

/** @var \ZPMLabs\SshManager\Contracts\SshManagerContract $manager */

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
use ZPMLabs\SshManager\Entities\SshEntryEntity;

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
/** @var \ZPMLabs\SshManager\Entities\SshEntryEntity|null $existing */
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

## Laravel Integration

This package works seamlessly with Laravel. Here's how to integrate it:

### Installation

```bash
composer require zpmlabs/ssh-manager
```

### Service Provider Registration

Create a service provider to bind the SSH manager in Laravel's service container:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use ZPMLabs\SshManager\Contracts\SshManagerContract;
use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Factories\SshManagerFactory;
use ZPMLabs\SshManager\Repositories\InMemorySshRepository;

class SshManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind repository (you can swap this with a database-backed implementation)
        $this->app->singleton(SshRepositoryContract::class, function ($app) {
            return new InMemorySshRepository();
            // Or use your own: return new DatabaseSshRepository();
        });

        // Bind SSH manager
        $this->app->singleton(SshManagerContract::class, function ($app) {
            $repository = $app->make(SshRepositoryContract::class);
            return SshManagerFactory::make($repository);
        });
    }
}
```

Register it in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\SshManagerServiceProvider::class,
],
```

### Using Laravel Collections

The package returns arrays, but you can easily wrap them in Laravel Collections for better functionality:

```php
use Illuminate\Support\Collection;
use ZPMLabs\SshManager\Contracts\SshManagerContract;

class SshEntryController extends Controller
{
    public function __construct(
        private SshManagerContract $sshManager
    ) {}

    public function index()
    {
        // Get entries as Laravel Collection
        $entries = collect($this->sshManager->listEntries());

        // Use Collection methods
        $groupedByOwner = $entries->groupBy(function ($entry) {
            return $entry->getOwnerId();
        });
        
        $withPublicKeys = $entries->filter(function ($entry) {
            return $entry->getPublicKey() !== null;
        });

        $usernames = $entries->map(function ($entry) {
            return $entry->getUsername();
        })->unique();

        return view('ssh-entries.index', [
            'entries' => $entries,
            'count' => $entries->count(),
        ]);
    }

    public function show(string $id)
    {
        $entry = $this->sshManager->findEntry($id);

        if (!$entry) {
            abort(404);
        }

        return view('ssh-entries.show', compact('entry'));
    }
}
```

### Creating Entries in Laravel

```php
use ZPMLabs\SshManager\Contracts\SshManagerContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;

class SshEntryController extends Controller
{
    public function store(Request $request, SshManagerContract $sshManager)
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'name' => 'nullable|string',
            'comment' => 'nullable|string',
        ]);

        $entry = new SshEntryEntity(
            id: '',
            username: $validated['username'],
            name: $validated['name'] ?? null,
            homeDirectory: null,
            publicKeyPath: null,
            privateKeyPath: null,
            publicKey: null,
            comment: $validated['comment'] ?? null,
            groups: [],
            ownerId: auth()->id(), // Link to authenticated user
            permissions: [],
        );

        // This will generate SSH keys on the system automatically
        $created = $sshManager->createEntry($entry);

        return redirect()
            ->route('ssh-entries.show', $created->getId())
            ->with('success', 'SSH entry created successfully!');
    }
}
```

### Using in Artisan Commands

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ZPMLabs\SshManager\Contracts\SshManagerContract;

class ListSshEntries extends Command
{
    protected $signature = 'ssh:list {--owner= : Filter by owner ID}';
    protected $description = 'List all SSH entries';

    public function handle(SshManagerContract $sshManager): int
    {
        $ownerId = $this->option('owner');
        $entries = collect($sshManager->listEntries($ownerId));

        if ($entries->isEmpty()) {
            $this->info('No SSH entries found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Username', 'Name', 'Home Directory', 'Public Key Path'],
            $entries->map(function ($entry) {
                return [
                    $entry->getId(),
                    $entry->getUsername(),
                    $entry->getName() ?? 'N/A',
                    $entry->getHomeDirectory() ?? 'N/A',
                    $entry->getPublicKeyPath() ?? 'N/A',
                ];
            })->toArray()
        );

        $this->info("Total: {$entries->count()} entries");

        return self::SUCCESS;
    }
}
```

### Scanning System Users

```php
use Illuminate\Support\Collection;
use ZPMLabs\SshManager\Contracts\SshManagerContract;

class SshEntryController extends Controller
{
    public function scanSystem(SshManagerContract $sshManager)
    {
        // Scan actual system users (OS-level, read-only)
        $systemEntries = collect($sshManager->scanSystemUsers());

        // Compare with repository entries
        $repositoryEntries = collect($sshManager->listEntries());
        
        $newEntries = $systemEntries->filter(function ($systemEntry) use ($repositoryEntries) {
            return !$repositoryEntries->contains(function ($repoEntry) use ($systemEntry) {
                return $repoEntry->getUsername() === $systemEntry->getUsername();
            });
        });

        return view('ssh-entries.scan', [
            'systemEntries' => $systemEntries,
            'repositoryEntries' => $repositoryEntries,
            'newEntries' => $newEntries,
        ]);
    }
}
```

### Generating Keys for Users

```php
use ZPMLabs\SshManager\Contracts\SshManagerContract;

class GenerateSshKeyCommand extends Command
{
    protected $signature = 'ssh:generate {username} {--label=}';
    protected $description = 'Generate SSH key pair for a system user';

    public function handle(SshManagerContract $sshManager): int
    {
        $username = $this->argument('username');
        $label = $this->option('label') ?: "{$username}@{$this->laravel->environment()}";

        try {
            $entry = $sshManager->generateKeyPairForUser(
                systemUsername: $username,
                label: $label,
                keyType: 'ed25519',
                bits: null
            );

            $this->info("SSH key pair generated successfully!");
            $this->line("Public key: {$entry->getPublicKeyPath()}");
            $this->line("Private key: {$entry->getPrivateKeyPath()}");
            
            if ($entry->getPublicKey()) {
                $this->line("Public key content:");
                $this->line($entry->getPublicKey());
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate key: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
```

### Database-Backed Repository (Optional)

For production use, you might want to store entries in a database. Here's a basic example:

```php
<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;
use ZPMLabs\SshManager\Entities\SshPermissionEntity;

class DatabaseSshRepository implements SshRepositoryContract
{
    public function create(SshEntryEntity $entry): SshEntryEntity
    {
        $id = DB::table('ssh_entries')->insertGetId([
            'username' => $entry->getUsername(),
            'name' => $entry->getName(),
            'home_directory' => $entry->getHomeDirectory(),
            'public_key_path' => $entry->getPublicKeyPath(),
            'private_key_path' => $entry->getPrivateKeyPath(),
            'public_key' => $entry->getPublicKey(),
            'comment' => $entry->getComment(),
            'groups' => json_encode($entry->getGroups()),
            'owner_id' => $entry->getOwnerId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find((string) $id) ?? $entry;
    }

    public function update(SshEntryEntity $entry): SshEntryEntity
    {
        DB::table('ssh_entries')
            ->where('id', $entry->getId())
            ->update([
                'username' => $entry->getUsername(),
                'name' => $entry->getName(),
                // ... update other fields
                'updated_at' => now(),
            ]);

        return $this->find($entry->getId()) ?? $entry;
    }

    public function delete(string $id): void
    {
        DB::table('ssh_entries')->where('id', $id)->delete();
    }

    public function find(string $id): ?SshEntryEntity
    {
        $row = DB::table('ssh_entries')->where('id', $id)->first();
        
        if (!$row) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    public function all(?string $ownerId = null): array
    {
        $query = DB::table('ssh_entries');
        
        if ($ownerId) {
            $query->where('owner_id', $ownerId);
        }

        return $query->get()->map(function ($row) {
            return $this->mapRowToEntity($row);
        })->toArray();
    }

    protected function mapRowToEntity($row): SshEntryEntity
    {
        return new SshEntryEntity(
            id: (string) $row->id,
            username: $row->username,
            name: $row->name,
            homeDirectory: $row->home_directory,
            publicKeyPath: $row->public_key_path,
            privateKeyPath: $row->private_key_path,
            publicKey: $row->public_key,
            comment: $row->comment,
            groups: json_decode($row->groups ?? '[]', true),
            ownerId: $row->owner_id,
            permissions: [], // Load from separate table if needed
        );
    }
}
```

Then update your service provider:

```php
$this->app->singleton(SshRepositoryContract::class, function ($app) {
    return new \App\Repositories\DatabaseSshRepository();
});
```

### Helper Methods with Collections

You can create helper methods that return Collections for easier use:

```php
<?php

namespace App\Services;

use Illuminate\Support\Collection;
use ZPMLabs\SshManager\Contracts\SshManagerContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;

class SshManagerService
{
    public function __construct(
        private SshManagerContract $manager
    ) {}

    public function entries(?string $ownerId = null): Collection
    {
        return collect($this->manager->listEntries($ownerId));
    }

    public function systemEntries(): Collection
    {
        return collect($this->manager->scanSystemUsers());
    }

    public function findByUsername(string $username): ?SshEntryEntity
    {
        return $this->entries()
            ->first(function ($entry) use ($username) {
                return $entry->getUsername() === $username;
            });
    }

    public function entriesForUser(int $userId): Collection
    {
        return $this->entries((string) $userId);
    }
}
```

---

## Notes

- All providers (Linux, Windows, macOS, Android) now have full CRUD implementations with SSH key generation.
- When creating entries, SSH keys are automatically generated on the system.
- All code and comments are kept framework-agnostic. You can easily build Laravel / Symfony integration on top of this package.
- The package works great with Laravel Collections for filtering, mapping, and transforming SSH entry data.
