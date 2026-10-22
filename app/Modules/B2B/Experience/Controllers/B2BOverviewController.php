<?php

namespace App\Modules\B2B\Experience\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class B2BOverviewController
{
    public function __invoke(): Response
    {
        return Inertia::render('B2B/Overview', [
            'workspaces' => [
                ['key' => 'inquiries', 'title' => '询盘管理', 'description' => '询盘收件箱、负责人分配及跟进记录', 'available' => false],
                ['key' => 'companies', 'title' => '企业客户', 'description' => '公司、采购联系人及客户关系', 'available' => false],
                ['key' => 'followups', 'title' => '跟进任务', 'description' => '下次跟进日期、提醒与跟进时间线', 'available' => false],
                ['key' => 'quotes', 'title' => '报价管理', 'description' => '报价版本、审核及与订单的关联', 'available' => false],
                ['key' => 'distributors', 'title' => '经销商管理', 'description' => '经销等级、区域授权与年度目标', 'available' => false],
            ],
        ]);
    }
}
