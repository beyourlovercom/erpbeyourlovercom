<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditRecord extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'];
    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
}
