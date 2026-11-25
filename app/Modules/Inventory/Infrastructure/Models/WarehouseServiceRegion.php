<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseServiceRegion extends Model
{
    public function setCountryCodeAttribute($value): void
    {
        $this->attributes['country_code'] = \App\Support\Countries\CountryStandard::forWrite($value, 'country_code', $this->getRawOriginal('country_code'));
    }

    protected $fillable = ['warehouse_id','country_code','priority'];
    protected $casts = ['priority'=>'integer'];
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
}
