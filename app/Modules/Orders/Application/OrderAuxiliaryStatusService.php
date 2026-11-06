<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Customer auxiliary follow-ups never overwrite operational shipment progress. */
final class OrderAuxiliaryStatusService
{
    private const PREFIX = 'workflow_aux_';

    public const CODES = [
        'missing_info',
        'returned_follow_up',
        'claims',
        'renew',
        'freight_product_cost_follow_up',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public static function isAuxiliary(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /** @return list<array{code:string,en:string,zh:string,color:string}> */
    public function for(Order $order): array
    {
        $order->loadMissing('operationalMarkers');
        $codes = $order->operationalMarkers->pluck('marker')
            ->filter(fn ($marker) => str_starts_with((string) $marker, self::PREFIX))
            ->map(fn ($marker) => substr((string) $marker, strlen(self::PREFIX)))
            ->filter(fn ($code) => self::isAuxiliary((string) $code))
            ->values()->all();
        if (self::isAuxiliary((string) $order->workflow_status)) {
            $codes[] = $order->workflow_status; // Legacy, read-only until explicitly changed.
        }

        return collect(array_values(array_unique($codes)))
            ->map(function (string $code): array {
                $definition = OrderWorkflowStatus::registry()[$code];

                return ['code' => $code, 'en' => $definition['en'],
                    'zh' => $definition['zh'], 'color' => $definition['color']];
            })->values()->all();
    }

    /** Keep older follow-up information when a legitimate workflow action overwrites its legacy field. */
    public function preserveLegacy(Order $order): void
    {
        $code = (string) $order->workflow_status;
        if (self::isAuxiliary($code)) {
            $order->operationalMarkers()->firstOrCreate(
                ['marker' => self::PREFIX.$code],
                ['marked_at' => now()]
            );
        }
    }

    public function add(Order $order, string $code, int $expectedRevision, Authenticatable $actor): Order
    {
        abort_unless(self::isAuxiliary($code), 422, '不是可用的辅助标记。');

        return DB::transaction(function () use ($order, $code, $expectedRevision, $actor): Order {
            $locked = $this->lock($order, $expectedRevision);
            $this->preserveLegacy($locked);
            $marker = self::PREFIX.$code;
            if (! $locked->operationalMarkers()->where('marker', $marker)->exists()) {
                $locked->operationalMarkers()->create([
                    'marker' => $marker,
                    'marked_by' => $actor->getAuthIdentifier(),
                    'marked_at' => now(),
                ]);
                $locked->forceFill(['workflow_revision' => (int) $locked->workflow_revision + 1])->save();
                $this->record($actor, 'order.auxiliary_status_added', $locked, $code);
            }

            return $locked->fresh(['owner', 'address', 'items', 'shipment', 'manualSync', 'operationalMarkers']);
        });
    }

    public function remove(Order $order, string $code, int $expectedRevision, Authenticatable $actor): Order
    {
        abort_unless(self::isAuxiliary($code), 422, '不是可用的辅助标记。');

        return DB::transaction(function () use ($order, $code, $expectedRevision, $actor): Order {
            $locked = $this->lock($order, $expectedRevision);
            $removed = $locked->operationalMarkers()->where('marker', self::PREFIX.$code)->delete();
            $legacy = $locked->workflow_status === $code;
            if ($legacy || $removed) {
                $locked->forceFill([
                    'workflow_status' => $legacy ? null : $locked->workflow_status,
                    'workflow_revision' => (int) $locked->workflow_revision + 1,
                ])->save();
                $this->record($actor, 'order.auxiliary_status_removed', $locked, $code);
            }

            return $locked->fresh(['owner', 'address', 'items', 'shipment', 'manualSync', 'operationalMarkers']);
        });
    }

    private function lock(Order $order, int $revision): Order
    {
        $locked = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->archived_at !== null || $locked->trashed() || $locked->isOperationallyArchived()) {
            throw new HttpException(409, '归档订单不允许修改辅助状态。');
        }
        if ((int) $locked->workflow_revision !== $revision) {
            throw new HttpException(409, '状态已变化，请刷新后重试。');
        }

        return $locked;
    }

    private function record(Authenticatable $actor, string $action, Order $order, string $code): void
    {
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            $action, Order::class, $order->id,
            ['auxiliary' => $code], ['revision' => $order->workflow_revision],
            'v3_auxiliary_annotation'
        );
    }
}
