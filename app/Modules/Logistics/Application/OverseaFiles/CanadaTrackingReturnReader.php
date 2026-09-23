<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class CanadaTrackingReturnReader
{
    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        throw new InvalidArgumentException('加拿大仓真实 Tracking 回传协议尚未冻结，请使用逐单 Tracking；禁止猜测回传字段。');
    }
}
