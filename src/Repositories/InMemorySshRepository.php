<?php

namespace ZPMLabs\SshManager\Repositories;

use ZPMLabs\SshManager\Contracts\SshRepositoryContract;
use ZPMLabs\SshManager\Entities\SshEntryEntity;

class InMemorySshRepository implements SshRepositoryContract
{
    /**
     * @var array<string,SshEntryEntity>
     */
    protected array $items = [];

    public function create(SshEntryEntity $entry): SshEntryEntity
    {
        $this->items[$entry->getId()] = $entry;

        return $entry;
    }

    public function update(SshEntryEntity $entry): SshEntryEntity
    {
        $this->items[$entry->getId()] = $entry;

        return $entry;
    }

    public function delete(string $id): void
    {
        unset($this->items[$id]);
    }

    public function find(string $id): ?SshEntryEntity
    {
        return $this->items[$id] ?? null;
    }

    public function all(?string $ownerId = null): array
    {
        if ($ownerId === null) {
            return array_values($this->items);
        }

        return array_values(array_filter(
            $this->items,
            fn (SshEntryEntity $entry) => $entry->getOwnerId() === $ownerId
        ));
    }
}
