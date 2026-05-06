<?php

namespace ZPMPackages\SshManager\Tests;

use PHPUnit\Framework\TestCase;
use ZPMPackages\SshManager\Entities\SshEntryEntity;
use ZPMPackages\SshManager\Entities\SshManagerCredentialsEntity;
use ZPMPackages\SshManager\Enums\OperatingSystem;
use ZPMPackages\SshManager\Factories\SshManagerFactory;
use ZPMPackages\SshManager\Repositories\InMemorySshRepository;

class SshManagerCrudTest extends TestCase
{
    private $repository;
    private $manager;
    private $testUsername;

    protected function setUp(): void
    {
        echo "\n=== Setting up test ===\n";
        
        $this->repository = new InMemorySshRepository();
        $this->manager = SshManagerFactory::make($this->repository);
        
        // Use current username for testing
        if (PHP_OS_FAMILY === 'Windows') {
            $this->testUsername = getenv('USERNAME') ?: getenv('USER') ?: 'Administrator';
        } else {
            $this->testUsername = get_current_user() ?: getenv('USER') ?: 'testuser';
        }
        
        echo "Test username: {$this->testUsername}\n";
        // The manager is an instance of AbstractSshManagerProvider which has getOsName()
        if ($this->manager instanceof \ZPMPackages\SshManager\Providers\AbstractSshManagerProvider) {
            echo "OS: " . $this->manager->getOsName() . "\n";
        } else {
            echo "OS: Unknown\n";
        }
    }

    public function testManagerCredentialsCanBePassedToProvider(): void
    {
        $credentials = new SshManagerCredentialsEntity(
            username: 'manager-user',
            password: 'manager-password',
        );

        $manager = SshManagerFactory::make(
            repository: new InMemorySshRepository(),
            os: OperatingSystem::WINDOWS,
            managerCredentials: $credentials,
        );

        $resolvedCredentials = $manager->getManagerCredentials();

        $this->assertTrue($manager->hasManagerCredentials());
        $this->assertNotNull($resolvedCredentials);
        $this->assertSame('manager-user', $resolvedCredentials->getUsername());
        $this->assertSame('manager-password', $resolvedCredentials->getPassword());
    }

    public function testManagerCanBeCreatedWithoutCredentials(): void
    {
        $manager = SshManagerFactory::make(
            repository: new InMemorySshRepository(),
            os: OperatingSystem::WINDOWS,
        );

        $this->assertFalse($manager->hasManagerCredentials());
        $this->assertNull($manager->getManagerCredentials());
    }

    public function testListAllEntries(): void
    {
        echo "\n=== Test: List All Entries ===\n";
        
        $entries = $this->manager->listEntries();
        
        echo "Found " . count($entries) . " entries\n";
        
        foreach ($entries as $entry) {
            echo "  - ID: {$entry->getId()}, Username: {$entry->getUsername()}\n";
        }
        
        $this->assertIsArray($entries);
    }

    public function testCreateNewEntry(): void
    {
        echo "\n=== Test: Create New Entry ===\n";
        
        $entry = new SshEntryEntity(
            id: '',
            username: $this->testUsername,
            name: 'Test SSH User',
            homeDirectory: null,
            publicKeyPath: null,
            privateKeyPath: null,
            publicKey: null,
            comment: 'Test entry created by PHPUnit',
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        $created = $this->manager->createEntry($entry);
        
        echo "Created entry:\n";
        echo "  ID: {$created->getId()}\n";
        echo "  Username: {$created->getUsername()}\n";
        echo "  Name: {$created->getName()}\n";
        echo "  Home: {$created->getHomeDirectory()}\n";
        echo "  Public Key Path: {$created->getPublicKeyPath()}\n";
        echo "  Private Key Path: {$created->getPrivateKeyPath()}\n";
        echo "  Public Key: " . substr($created->getPublicKey() ?? '', 0, 50) . "...\n";
        
        $this->assertNotEmpty($created->getId());
        $this->assertEquals($this->testUsername, $created->getUsername());
        $this->assertNotNull($created->getPublicKeyPath());
        $this->assertNotNull($created->getPrivateKeyPath());
        $this->assertNotNull($created->getPublicKey());
        $this->assertFileExists($created->getPublicKeyPath());
        $this->assertFileExists($created->getPrivateKeyPath());
    }

    public function testUpdateCreatedEntry(): void
    {
        echo "\n=== Test: Update Created Entry ===\n";
        
        // First create an entry
        $entry = new SshEntryEntity(
            id: '',
            username: $this->testUsername,
            name: 'Original Name',
            homeDirectory: null,
            publicKeyPath: null,
            privateKeyPath: null,
            publicKey: null,
            comment: 'Original comment',
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        $created = $this->manager->createEntry($entry);
        $entryId = $created->getId();
        
        echo "Created entry with ID: {$entryId}\n";
        
        // Now update it
        $updatedEntry = new SshEntryEntity(
            id: $entryId,
            username: $this->testUsername,
            name: 'Updated Name',
            homeDirectory: $created->getHomeDirectory(),
            publicKeyPath: $created->getPublicKeyPath(),
            privateKeyPath: $created->getPrivateKeyPath(),
            publicKey: $created->getPublicKey(),
            comment: 'Updated comment',
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        $updated = $this->manager->updateEntry($updatedEntry);
        
        echo "Updated entry:\n";
        echo "  ID: {$updated->getId()}\n";
        echo "  Name: {$updated->getName()}\n";
        echo "  Comment: {$updated->getComment()}\n";
        
        $this->assertEquals($entryId, $updated->getId());
        $this->assertEquals('Updated Name', $updated->getName());
        $this->assertEquals('Updated comment', $updated->getComment());
    }

    // public function testDeleteEntry(): void
    // {
    //     echo "\n=== Test: Delete Entry ===\n";
        
    //     // First create an entry
    //     $entry = new SshEntryEntity(
    //         id: '',
    //         username: $this->testUsername,
    //         name: 'Entry to Delete',
    //         homeDirectory: null,
    //         publicKeyPath: null,
    //         privateKeyPath: null,
    //         publicKey: null,
    //         comment: 'This will be deleted',
    //         groups: [],
    //         ownerId: null,
    //         permissions: [],
    //     );
        
    //     $created = $this->manager->createEntry($entry);
    //     $entryId = $created->getId();
        
    //     echo "Created entry with ID: {$entryId}\n";
        
    //     // Verify it exists
    //     $found = $this->manager->findEntry($entryId);
    //     $this->assertNotNull($found);
    //     echo "Entry found before deletion\n";
        
    //     // Delete it
    //     $this->manager->deleteEntry($entryId);
        
    //     echo "Entry deleted\n";
        
    //     // Verify it's gone
    //     $foundAfter = $this->manager->findEntry($entryId);
    //     $this->assertNull($foundAfter);
    //     echo "Entry confirmed deleted from repository\n";
        
    //     // Keys should still exist on filesystem (we keep them for safety)
    //     if ($created->getPrivateKeyPath() && file_exists($created->getPrivateKeyPath())) {
    //         echo "Keys still exist on filesystem (as expected)\n";
    //     }
    // }

    public function testFullCrudWorkflow(): void
    {
        echo "\n=== Test: Full CRUD Workflow ===\n";
        
        // 1. List (should be empty or have existing entries)
        echo "1. Listing initial entries...\n";
        $initialEntries = $this->manager->listEntries();
        $initialCount = count($initialEntries);
        echo "   Initial count: {$initialCount}\n";
        
        // 2. Create
        echo "2. Creating new entry...\n";
        $entry = new SshEntryEntity(
            id: '',
            username: $this->testUsername,
            name: 'Workflow Test User',
            homeDirectory: null,
            publicKeyPath: null,
            privateKeyPath: null,
            publicKey: null,
            comment: 'Full workflow test',
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        $created = $this->manager->createEntry($entry);
        $entryId = $created->getId();
        echo "   Created with ID: {$entryId}\n";
        
        // 3. List again (should have one more)
        echo "3. Listing after create...\n";
        $afterCreateEntries = $this->manager->listEntries();
        $afterCreateCount = count($afterCreateEntries);
        echo "   Count after create: {$afterCreateCount}\n";
        $this->assertGreaterThan($initialCount, $afterCreateCount);
        
        // 4. Update
        echo "4. Updating entry...\n";
        $updatedEntry = new SshEntryEntity(
            id: $entryId,
            username: $this->testUsername,
            name: 'Updated Workflow User',
            homeDirectory: $created->getHomeDirectory(),
            publicKeyPath: $created->getPublicKeyPath(),
            privateKeyPath: $created->getPrivateKeyPath(),
            publicKey: $created->getPublicKey(),
            comment: 'Updated workflow test',
            groups: [],
            ownerId: null,
            permissions: [],
        );
        
        $updated = $this->manager->updateEntry($updatedEntry);
        echo "   Updated name: {$updated->getName()}\n";
        $this->assertEquals('Updated Workflow User', $updated->getName());
        
        // 5. Delete
        echo "5. Deleting entry...\n";
        $this->manager->deleteEntry($entryId);
        echo "   Deleted\n";
        
        // 6. List again (should be back to initial)
        echo "6. Listing after delete...\n";
        $afterDeleteEntries = $this->manager->listEntries();
        $afterDeleteCount = count($afterDeleteEntries);
        echo "   Count after delete: {$afterDeleteCount}\n";
        $this->assertEquals($initialCount, $afterDeleteCount);
        
        echo "\n=== Full CRUD Workflow Completed Successfully ===\n";
    }
}

