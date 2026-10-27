<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductParameterSubmission extends Model
{
    protected $fillable = ['product_id', 'task_id', 'created_by', 'status', 'measurements', 'accessories',
        'notes', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['measurements' => 'array', 'accessories' => 'array',
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductParameterMedia::class, 'submission_id');
    }
}
