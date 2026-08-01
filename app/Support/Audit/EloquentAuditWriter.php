<?php

namespace App\Support\Audit;

use App\Models\AuditRecord;
use Illuminate\Support\Facades\DB;

final class EloquentAuditWriter implements AuditWriter
{
    public function write(array $attributes): AuditRecord
    {
        foreach (['before', 'after'] as $field) {
            if (is_array($attributes[$field] ?? null)) {
                $attributes[$field] = json_encode($attributes[$field], JSON_THROW_ON_ERROR);
            }
        }

        $id = DB::table('audit_records')->insertGetId($attributes);

        return AuditRecord::query()->findOrFail($id);
    }
}
