<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\Xlwms\SingleOrderPreview;
use App\Modules\Logistics\Application\Xlwms\UsNyCanaryDispatch;
use App\Modules\Logistics\Application\Xlwms\UsNyTrackingStage;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Throwable;

/** Owner-authorized production CLI only; no arbitrary order, secret or user impersonation. */
final class UsNyCanaryCommand extends Command
{
    protected $signature = 'xlwms:us-ny-canary {order : Exact ERP order number} {--execute} {--refresh} {--confirmation=}';
    protected $description = 'One owner-authorized US-NY canary or exact signed tracking refresh';

    public function handle(SingleOrderPreview $preview, UsNyCanaryDispatch $dispatch, UsNyTrackingStage $tracking): int
    {
        try {
            $orderNo = (string)$this->argument('order');
            if (!app()->environment('production') || !preg_match('/^[A-Za-z0-9-]{3,80}$/D',$orderNo)
                || ($this->option('execute') && $this->option('refresh'))) return self::FAILURE;
            $o = Order::query()->eligibleForOperations()->where('erp_order_id',$orderNo)
                ->with(['shipment.warehouse','shipment.overseaReview'])->sole();
            if ($o->shipment?->warehouse?->code !== 'US-NY'
                || $o->shipment->overseaReview?->status !== 'approved'
                || (int)$o->shipment->overseaReview->reviewed_warehouse_id !== (int)$o->shipment->warehouse_id)
                return self::FAILURE;
            if ($this->option('refresh')) {
                $r = $tracking->refreshAndStage((int)$o->id);
                $this->line(json_encode($r,JSON_THROW_ON_ERROR));
                return ($r['fresh']??false)?self::SUCCESS:self::FAILURE;
            }
            $p = $preview->forOrder($o,false,false,'US-NY');
            $this->line(json_encode(['order'=>$orderNo,'blockers'=>$p['blockers'],
                'attempt_exists'=>XlwmsDispatchAttempt::where('order_id',$o->id)->exists()],JSON_THROW_ON_ERROR));
            if (!$this->option('execute')) return $p['blockers']===[]?self::SUCCESS:self::FAILURE;
            if ($p['blockers'] !== [] || !hash_equals('OWNER_APPROVED_US_NY_'.$orderNo,(string)$this->option('confirmation'))
                || !(bool)config('xlwms.us_ny_canary_enabled',false)
                || (int)config('xlwms.us_ny_canary_order_id') !== (int)$o->id) return self::FAILURE;
            config()->set('xlwms.us_ny_canary_system_enabled',true);
            try {
                $r=$dispatch->create((int)$o->id,null,$p['payload_hash']);
                $this->line(json_encode($r,JSON_THROW_ON_ERROR));
                if ($r['state']!=='oms_create_confirmed') return self::FAILURE;
                $this->line(json_encode($tracking->refreshAndStage((int)$o->id),JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            } finally {config()->set('xlwms.us_ny_canary_system_enabled',false);}
        } catch(Throwable) {
            $this->error('US_NY_CANARY_BLOCKED_OR_RECONCILE_REQUIRED; NO_BLIND_RETRY');
            return self::FAILURE;
        }
    }
}
