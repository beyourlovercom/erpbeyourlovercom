<?php

namespace App\Modules\Kol\Application;

use App\Modules\Kol\Infrastructure\Models\Kol;
use Illuminate\Database\Eloquent\Builder;

final class KolDuplicateIdentity
{
    // Match exact identities, case-insensitively. Never treat '_' or '%' as SQL LIKE wildcards.
    // Include archived KOL records and affiliated/social aliases to prevent repeated registration.
    public static function find(string $username, string $displayName): ?Kol
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (string $text): string => mb_strtolower(trim($text)),
            [$username, $displayName],
        ), static fn (string $text): bool => $text !== '')));
        if ($names === []) {
            return null;
        }

        $marks = implode(',', array_fill(0, count($names), '?'));
        $socialNames = array_values(array_unique(array_merge($names, array_map(
            static fn (string $name): string => '@'.$name, $names
        ))));
        $socialMarks = implode(',', array_fill(0, count($socialNames), '?'));

        return Kol::query()->where(function (Builder $query) use ($names, $marks, $socialNames, $socialMarks): void {
            $query->whereRaw("LOWER(TRIM(display_name)) IN ($marks)", $names)
                ->orWhereHas('affiliateAccounts', fn (Builder $accounts) =>
                    $accounts->whereRaw("LOWER(TRIM(wordpress_user_login)) IN ($marks)", $names))
                ->orWhereHas('socialAccounts', fn (Builder $accounts) =>
                    $accounts->whereRaw("LOWER(TRIM(handle)) IN ($socialMarks)", $socialNames));
        })->orderBy('id')->first(['id', 'display_name', 'owner_id']);
    }
}
