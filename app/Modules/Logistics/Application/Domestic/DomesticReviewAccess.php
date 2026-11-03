<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class DomesticReviewAccess
{
    public function allows(?Authenticatable $actor): bool
    {
        return $actor instanceof User
            && $actor->hasPermission('domestic.review');
    }
}
