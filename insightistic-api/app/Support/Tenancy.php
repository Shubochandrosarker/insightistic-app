<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Site;

/**
 * Request-scoped tenant context. Bound as a singleton per request.
 * Set by ResolveOrganization (user requests) or ConnectorAuth (plugin requests).
 * Read by the BelongsToOrganization global scope so tenant filtering happens
 * automatically on every query — developers never have to remember it.
 */
class Tenancy
{
    protected ?Organization $organization = null;
    protected ?Site $site = null;

    public function setOrganization(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function setSite(Site $site): void
    {
        $this->site = $site;
    }

    public function site(): ?Site
    {
        return $this->site;
    }
}
