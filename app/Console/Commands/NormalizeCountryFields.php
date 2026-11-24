<?php

namespace App\Console\Commands;

use App\Support\Countries\CountryBackfill;
use Illuminate\Console\Command;

final class NormalizeCountryFields extends Command
{
    protected $signature = 'erp:country-standard {--apply} {--expected-hash=} {--production-approved}';
    protected $description = '预检或受控转换国家字段；默认只读。';

    public function handle(CountryBackfill $backfill): int
    {
        if ($this->option('apply')) {
            if (! $this->option('expected-hash') || (app()->environment('production') && ! $this->option('production-approved'))) {
                $this->error('必须提供已审批的预检指纹；正式环境另需发布授权及备份。');
                return self::FAILURE;
            }
            $plan = $backfill->apply((string) $this->option('expected-hash'));
        } else {
            $plan = $backfill->plan();
        }
        $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
