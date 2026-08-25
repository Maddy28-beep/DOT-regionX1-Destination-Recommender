<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['tourist_id', 'user_query', 'chatbot_response', 'intent_detected'];

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(Tourist::class);
    }
}
