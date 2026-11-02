<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderWorkflowStatusService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function set(Order $order, string $next, int $expectedRevision, Authenticatable $actor): Order
    {
        OrderWorkflowStatus::assertMutable($next);
        return DB::transaction(function () use ($order, $next, $expectedRevision, $actor): Order {
            $locked = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null || $locked->trashed()) {
                throw new HttpException(422, 'Archived or deleted orders cannot change workflow status.');
            }
            $current = $locked->workflow_status;
            if ((int) $locked->workflow_revision !== $expectedRevision) {
                throw new HttpException(409, 'Workflow status is stale; refresh and retry.');
            }
            if ($current === $next || OrderWorkflowStatus::effectiveCode($current, $locked->order_status->value) === $next) {
                return $locked->load('owner', 'address', 'items', 'shipment', 'manualSync');
            }
            $revision = (int) $locked->workflow_revision + 1;
            $locked->forceFill(['workflow_status' => $next, 'workflow_revision' => $revision])->save();
            OrderWorkflowStatusHistory::query()->create(['order_id' => $locked->id, 'actor_id' => $actor->getAuthIdentifier(), 'from_status' => $current, 'to_status' => $next, 'revision' => $revision]);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'order.workflow_status_changed', Order::class, $locked->id, ['workflow_status' => $current], ['workflow_status' => $next, 'workflow_revision' => $revision], 'staff_workflow_progress');
            return $locked->fresh(['owner', 'address', 'items', 'shipment', 'manualSync']);
        });
    }
}
