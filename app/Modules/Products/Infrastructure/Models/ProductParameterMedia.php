<?php

namespace App\Modules\Products\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductParameterMedia extends Model
{
    protected $fillable = ['submission_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'];
}
