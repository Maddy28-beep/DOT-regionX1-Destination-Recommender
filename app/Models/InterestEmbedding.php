<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterestEmbedding extends Model
{
    protected $fillable = ['interest', 'model', 'dimensions', 'text_hash', 'vector'];

    protected function casts(): array
    {
        return ['vector' => 'array'];
    }
}
