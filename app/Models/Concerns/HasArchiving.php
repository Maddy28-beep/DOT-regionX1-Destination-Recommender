<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasArchiving
{
    public function initializeHasArchiving(): void
    {
        $this->fillable[] = 'archived_at';
        $this->casts['archived_at'] = 'datetime';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->where('is_accredited', true);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }
}
