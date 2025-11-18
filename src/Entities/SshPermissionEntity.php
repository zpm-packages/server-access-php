<?php

namespace ZPMLabs\SshManager\Entities;

class SshPermissionEntity
{
    public function __construct(
        protected string $userId,
        protected bool $canRead = true,
        protected bool $canWrite = false,
        protected bool $canManage = false, // CRUD over entry
    ) {}

    public function getUserId(): string    { return $this->userId; }
    public function canRead(): bool        { return $this->canRead; }
    public function canWrite(): bool       { return $this->canWrite; }
    public function canManage(): bool      { return $this->canManage; }
}
