<?php

namespace App\Support\Elections;

use App\Models\Election;
use App\Models\Organization;
use App\Models\RollEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where elections are being run from: the NACOS admin area, or one
 * department's own space (docs/department-elections.md). The setup screens
 * (resources/views/elections/manage) and their controllers are shared; this
 * tells them which routes, layout, roll and elections to use.
 */
final class ElectionDesk
{
    private function __construct(public readonly ?Organization $organization) {}

    public static function nacos(): self
    {
        return new self(null);
    }

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    public function isDepartment(): bool
    {
        return $this->organization !== null;
    }

    /**
     * A setup route: '' for the list, '.show', '.positions.store'…
     *
     * @param  mixed  $parameters
     */
    public function route(string $name, $parameters = []): string
    {
        // The department in the URL is filled in by the "vote" middleware.
        return route(($this->organization ? 'vote.admin.elections' : 'elections.manage').$name, $parameters);
    }

    /** The page layout component. */
    public function layout(): string
    {
        return $this->organization ? 'layouts.vote-admin' : 'layouts.admin';
    }

    /**
     * @return Builder<Election>|HasMany<Election, Organization>
     */
    public function elections(): Builder|HasMany
    {
        return $this->organization ? $this->organization->elections() : Election::query();
    }

    public function owns(Election $election): bool
    {
        return $election->organization_id === $this->organization?->id;
    }

    public function rollCount(): int
    {
        return $this->organization ? $this->organization->roll()->count() : RollEntry::query()->count();
    }

    public function rollUrl(): string
    {
        return $this->organization ? route('vote.admin.roll') : route('roll.index');
    }

    /** Where students see the election (ballot and results). */
    public function publicUrl(Election $election): string
    {
        return $this->organization ? route('vote.elections.show', $election) : route('elections.show', $election);
    }

    /** The list of elections students see, for the setup list's back link. */
    public function publicIndexUrl(): string
    {
        return $this->organization ? route('vote.home') : route('elections.index');
    }
}
