<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Domestic\DomesticReviewAccess;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\WarehouseBatchReturnAllocationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WarehouseBatchReturnAllocationController
{
    public function __construct(
        private readonly WarehouseBatchReturnAllocationService $returns,
        private readonly DomesticReviewAccess $domesticAccess,
        private readonly LogisticsReviewAccess $overseaAccess,
    ) {}

    public function domestic(Request $request): JsonResponse
    {
        abort_unless($this->domesticAccess->allows($request->user()), 403);

        return $this->apply($request, 'domestic');
    }

    public function oversea(Request $request): JsonResponse
    {
        abort_unless($this->overseaAccess->allows($request->user()), 403);

        return $this->apply($request, 'oversea');
    }

    private function apply(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $results = [];
        foreach ($data['order_ids'] as $rawId) {
            $id = (int) $rawId;
            try {
                $order = Order::withTrashed()->findOrFail($id);
                $this->returns->returnToAllocation($order, $request->user(), $source);
                $results[] = ['order_id' => $id, 'ok' => true, 'message' => $order->fulfillment_parent_id
                    ? '子订单已进入独立履约异常队列，请在履约发货重新分仓'
                    : '已进入待分仓，旧发货仓已清空'];
            } catch (ModelNotFoundException) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '订单不存在'];
            } catch (HttpExceptionInterface $exception) {
                $results[] = ['order_id' => $id, 'ok' => false,
                    'message' => $exception->getStatusCode() === 403 ? '无操作权限' : ($exception->getMessage() ?: '订单状态已变化')];
            }
        }

        return response()->json([
            'results' => $results,
            'success_count' => count(array_filter($results, fn (array $item): bool => $item['ok'])),
            'failed_count' => count(array_filter($results, fn (array $item): bool => ! $item['ok'])),
        ]);
    }
}
