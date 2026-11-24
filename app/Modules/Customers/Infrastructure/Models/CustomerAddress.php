<?php

namespace App\Modules\Customers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    public function setCountryCodeAttribute($value): void
    {
        $this->attributes['country_code'] = \App\Support\Countries\CountryStandard::forWrite($value, 'country_code', $this->getRawOriginal('country_code'));
    }

    protected $fillable = [
        'customer_id', 'address_type',
        'name', 'company', 'phone', 'country_code', 'province_state',
        'city', 'address_line_1', 'address_line_2', 'postal_code',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
