<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chatbot exchange, kept for tuning the assistant.
 *
 * Anonymous: the site has no tourist accounts, so a log row records what was
 * asked and answered and nothing about who asked it.
 */
class ChatbotLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_query', 'chatbot_response', 'intent_detected'];
}
