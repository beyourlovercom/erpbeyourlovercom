<?php

namespace App\Modules\Orders\Experience\Console;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RepairCollaborationOrderIdentitiesCommand extends Command
{
    protected $signature = 'orders:repair-collaboration-identities {--ids= : Exact comma-separated root order IDs} {--apply : Apply the reviewed plan} {--expect-change= : Required count of changes}';

    protected $description = 'Preview or repair explicitly selected unallocated collaboration order identities';

    public function handle(OrderIdentityService $identities, AuditRecorder $audit): int
    {
        $raw = (string) $this->option('ids');
        if (preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw) !== 1) {
            $this->error('必须明确指定订单数据库 ID。');

            return self::FAILURE;
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        $apply = (bool) $this->option('apply');

        try {
            return DB::transaction(function () use ($ids, $apply, $identities, $audit): int {
                $orders = Order::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
                if ($orders->count() !== count($ids)) {
                    throw new \RuntimeException('部分订单不存在或已删除。');
                }
                $plan = [];
                $targets = [];
                foreach ($orders as $order) {
                    if ($order->source_type->value !== 'collaboration' || $order->fulfillment_parent_id !== null) {
                        throw new \RuntimeException('仅允许修改指定合作母订单：'.$order->id);
                    }
                    $target = $identities->previewCollaboration($order->collaboration_partner_id, $order->external_order_number);
                    if ($target === $order->erp_order_id) {
                        continue;
                    }
                    if ($order->order_status->value !== 'pending_shipment' || $order->archived_at !== null
                        || $order->operational_archived_at !== null || $order->routing_warehouse_code !== null
                        || $order->shipment()->exists() || $order->fulfillmentChildren()->exists()
                        || $order->fulfillmentAllocation()->exists() || $order->externalReferences()->exists()) {
                        throw new \RuntimeException('订单已经进入物流或非待处理状态，禁止直接改号：'.$order->id);
                    }
                    if (isset($targets[$target]) || Order::withTrashed()->whereRaw('UPPER(erp_order_id) = ?', [$target])->where('id', '<>', $order->id)->exists()) {
                        throw new \RuntimeException('目标编号冲突：'.$target);
                    }
                    $targets[$target] = true;
                    $plan[] = [$order, $target];
                }
                foreach ($plan as [$order, $target]) {
                    $this->line($order->id.' '.$order->erp_order_id.' -> '.$target);
                }
                $this->line('change='.count($plan));
                if (! $apply) {
                    return self::SUCCESS;
                }
                if ((string) $this->option('expect-change') !== (string) count($plan)) {
                    throw new \RuntimeException('改号数量与审核计划不符。');
                }
                foreach ($plan as [$order, $target]) {
                    $before = $order->erp_order_id;
                    $order->forceFill(['erp_order_id' => $target])->save();
                    $audit->record(
                        AuditActor::system(AuditSource::InternalSystem),
                        'order.erp_identity_repaired',
                        Order::class,
                        $order->id,
                        before: ['erp_order_id' => $before],
                        after: ['erp_order_id' => $target],
                        reason: 'owner_rule_collaboration_prefix_last5_007',
                    );
                }
                $this->info('applied='.count($plan));

                return self::SUCCESS;
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
