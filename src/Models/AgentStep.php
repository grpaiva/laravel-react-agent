<?php

namespace Grpaiva\LaravelReactAgent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_session_id',
        'type',
        'content',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'agent_session_id');
    }
}
