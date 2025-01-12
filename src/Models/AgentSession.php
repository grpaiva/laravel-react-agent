<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'objective',
        'context',
        'final_answer',
    ];

    protected $casts = [
        'context' => 'array', // store system prompts or other structured data
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class);
    }
}
