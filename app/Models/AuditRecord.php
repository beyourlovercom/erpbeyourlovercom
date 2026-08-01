<?php

namespace App\Models;

use App\Support\Audit\ImmutableAuditBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

class AuditRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function newEloquentBuilder($query): Builder
    {
        /** @var QueryBuilder $query */
        return new ImmutableAuditBuilder($query);
    }

    protected static function booted(): void
    {
        static::creating(fn () => throw new LogicException('Audit records must be created through AuditService.'));
        static::updating(fn () => throw new LogicException('Audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit records are immutable.'));
    }
}
