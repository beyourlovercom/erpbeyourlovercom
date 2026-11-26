<?php

namespace App\Modules\Logistics\Application;

use DomainException;

/** Identity for original split segments; historical ERP identities are never recycled. */
final class FulfillmentOriginalIdentity
{
    public const MAX_GENERATION = 99;

    public static function suffix(int $index, int $generation): string
    {
        if ($index < 0 || $index >= 26 || $generation < 1 || $generation > self::MAX_GENERATION) {
            throw new DomainException('履约编号超出范围');
        }

        return chr(ord('A') + $index).($generation === 1 ? '' : '-'.$generation);
    }

    public static function generation(string $suffix): ?int
    {
        if (preg_match('/^[A-Z]$/D', $suffix)) {
            return 1;
        }
        if (preg_match('/^[A-Z]-([2-9]|[1-9][0-9])$/D', $suffix, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function index(string $suffix): int
    {
        if (self::generation($suffix) === null) {
            throw new DomainException('履约后缀无效');
        }

        return ord($suffix[0]) - ord('A');
    }

    /**
     * Active segments retain their generation during ordinary pending edits.
     * With no active segment, allocate the next generation after every occupied
     * historical identity (including soft-deleted rows).
     *
     * @param list<string> $active
     * @param list<string> $occupied
     */
    public static function forAllocation(array $active, array $occupied, int $count): int
    {
        if ($count < 1 || $count > 26) {
            throw new DomainException('履约段序号超出范围');
        }

        if ($active !== []) {
            $generations = array_unique(array_map(self::generation(...), $active), SORT_REGULAR);
            if (count($generations) !== 1 || reset($generations) === null) {
                throw new DomainException('当前履约编号不一致，请核实历史订单');
            }
            $generation = (int) reset($generations);
        } else {
            $generation = 1;
            foreach ($occupied as $suffix) {
                $generation = max($generation, (self::generation($suffix) ?? 0) + 1);
            }
        }

        for ($index = 0; $index < $count; $index++) {
            $suffix = self::suffix($index, $generation);
            if (in_array($suffix, $occupied, true) && ! in_array($suffix, $active, true)) {
                throw new DomainException('履约编号已被历史订单占用，请先完成重新分仓');
            }
        }

        return $generation;
    }
}
