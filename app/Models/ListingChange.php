<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingChange extends Model
{
    /** Changes applied to the listing's own columns or lists when approved. */
    public const KINDS = ['details', 'hours', 'position', 'amenities', 'photo', 'promotion'];

    protected $fillable = ['listing_submission_id', 'kind', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ListingSubmission::class, 'listing_submission_id');
    }
}
