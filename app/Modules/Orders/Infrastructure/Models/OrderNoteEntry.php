<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class OrderNoteEntry extends Model
{
    protected $fillable = ['order_id', 'author_user_id', 'author_name', 'body'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order notes are append-only.'));
        static::deleting(fn () => throw new LogicException('Order notes cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Identity\Infrastructure\Models\User::class, 'author_user_id');
    }
}
