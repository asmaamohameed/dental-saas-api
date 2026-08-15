<?php

namespace App\Support\Tenancy;

class CurrentTenant
{
    protected string|int|null $id = null;

    public function set(string|int|null $tenantId): void
    {
        $this->id = $tenantId;
    }

    public function id(): string|int|null
    {
        return $this->id;
    }

    public function check(): bool
    {
        return $this->id !== null;
    }
}
