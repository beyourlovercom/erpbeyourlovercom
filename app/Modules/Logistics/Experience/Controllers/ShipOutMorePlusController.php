<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\ShipOut\ShipOutOutboundPayloadFactory;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutTrackingDecision;
use App\Modules\Logistics\Application\ShipOut\ShipOutTrackingApplyService;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dede's existing US-LA workbench; explicitly sandbox-only.
 * No ERP shipment/WooCommerce write is performed by these actions.
 */
final class ShipOutMorePlusController
{
    public function preflight(
        Order $order,
        ShipOutOutboundPayloadFactory $factory,
    ): JsonResponse {
        $this->guardWarehouse($order);
        $date = now('America/New_York')->addDay()->format('Y-m-d');
        $attempt = $this->attempt($order);
        $blockers = [];

        if (! (bool) config('shipout.sandbox_read_enabled', false)) {
            $blockers[] = 'SANDBOX_API_READ_DISABLED';
        }
        if (! (bool) config('shipout.sandbox_dispatch_enabled', false)) {
            $blockers[] = 'SANDBOX_DISPATCH_DISABLED';
        }

        $whId = trim((string) config('shipout.sandbox_warehouse_id', ''));
        if ($whId === '') {
            $blockers[] = 'SANDBOX_WAREHOUSE_NOT_BOUND';
        }
        if ($attempt !== null) {
            $blockers[] = 'ALREADY_ATTEMPTED_QUERY_FIRST';
        }

        $hash = null;
        $items = [];
        $orderNo = null;
        try {
            $payload = $factory->fromApprovedOrder($order, $whId, $date);
            $orderNo = (string) $payload[0]['orderNo'];
            $items = $payload[0]['shipmentForms'][0]['productList'];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if ($orderNo !== trim((string) config('shipout.sandbox_order_allowlist', ''))) {
                $blockers[] = 'ORDER_NOT_IN_SANDBOX_ALLOWLIST';
            }
        } catch (RuntimeException|DomainException $e) {
            $blockers[] = $e->getMessage();
        }
        return response()->json([
            'order_id' => (int) $order->id,
            'ship_date' => $date,
            'order_no' => $orderNo,
            'items' => $items,
            'blockers' => array_values(array_unique($blockers)),
            'ready' => $blockers === [],
            'payload_hash' => $blockers === [] ? $hash : null,
            'already_attempted' => $attempt !== null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function send(
        Order $order,
        Request $request,
        ShipOutSandboxDispatchService $dispatch,
    ): JsonResponse {
        $this->guardWarehouse($order);
        $data = $request->validate([
            'ship_date' => ['required', 'date_format:Y-m-d'],
            'payload_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'confirmation' => ['required', 'string', 'max:90'],
        ]);
        abort_unless(hash_equals('SUBMIT_US_LA_ORDER_'.$order->id, $data['confirmation']),
            422, '单笔发货确认不一致');
        try {
            $result = $dispatch->dispatchApprovedOrder(
                (int) $order->id,
                $data['ship_date'],
                $data['payload_hash'],
            );
        } catch (RuntimeException|DomainException $e) {
            return $this->failure($e->getMessage());
        }
        return response()->json([
            'order_id' => (int) $order->id,
            'status' => $result['status'],
            'provider_order_id' => $result['provider_order_id'],
            'result_code' => $result['result_code'],
            'erp_shipment_unchanged' => true,
            'woo_unchanged' => true,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function status(
        Order $order,
        WarehouseOrderReference $refs,
        ShipOutReadOnlyGateway $gateway,
        ShipOutTrackingDecision $tracking,
    ): JsonResponse {
        $this->guardWarehouse($order);
        if (! (bool) config('shipout.sandbox_read_enabled', false)) {
            return response()->json([
                'state' => 'disabled',
                'reason' => 'SANDBOX_API_READ_DISABLED',
                'read_only' => true,
            ])->header('Cache-Control', 'no-store, private');
        }
        $whId = trim((string) config('shipout.sandbox_warehouse_id', ''));
        abort_if($whId === '', 409, 'ShipOut 测试仓库尚未绑定');
        try {
            $orderNo = $refs->warehouse($order);
            $page = $gateway->orderPage($whId, $orderNo, 1, 10);
            if ($page['total'] === 0) {
                return response()->json([
                    'state' => 'not_found',
                    'reason' => 'PROVIDER_ORDER_NOT_FOUND',
                    'order_no' => $orderNo,
                    'read_only' => true,
                    'erp_shipment_unchanged' => true,
                ])->header('Cache-Control', 'no-store, private');
            }
            if ($page['total'] !== 1 || count($page['records']) !== 1) {
                throw new RuntimeException('SHIPOUT_ORDER_IDENTITY_AMBIGUOUS');
            }
            $record = $page['records'][0];
            $detail = $gateway->orderDetail($whId, $record['orderId']);
            if ($detail['orderNO'] !== $orderNo) {
                throw new RuntimeException('SHIPOUT_ORDER_IDENTITY_MISMATCH');
            }
            $evaluation = $tracking->evaluate($detail);
            return response()->json([
                'order_no' => $orderNo,
                'provider_order_id' => $record['orderId'],
                'provider_order_status' => $detail['status'],
                'state' => $evaluation['status'],
                'reason' => $evaluation['reason'],
                'tracking_number' => $evaluation['tracking_number'] ?? null,
                'carrier_id' => $evaluation['carrier_id'] ?? null,
                'shipped_time_utc' => $evaluation['shipped_time_utc'] ?? null,
                'read_only' => true,
                'erp_shipment_unchanged' => true,
                'woo_unchanged' => true,
            ])->header('Cache-Control', 'no-store, private');

        } catch (RuntimeException|DomainException $e) {
            return $this->failure($e->getMessage());
        }
    }

    /** Explicit Dede confirmation after fresh signed provider re-query.
     * Never pushes to Woo directly.
     */
    public function apply(
        Order $order,
        Request $request,
        ShipOutTrackingApplyService $service,
    ): JsonResponse {
        $this->guardWarehouse($order);
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'string', 'max:90'],
        ]);
        abort_unless(hash_equals('APPLY_US_LA_TRACKING_'.$order->id, $data['confirmation']),
            422, 'Tracking ERP 回写确认不一致');
        try {
            $result = $service->apply((int) $order->id, $data['tracking_number'], $request->user());
        } catch (RuntimeException|DomainException $e) {
            return $this->failure($e->getMessage());
        }
        return response()->json([
            'order_id' => (int) $order->id,
            'status' => $result['status'],
            'tracking_number' => $result['tracking_number'],
            'woo_sync' => $result['woo_sync'],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Only fixed machine-readable errors from our guard/gateway; no raw provider PII. */
    private function failure(string $reason): JsonResponse
    {
        $known = [
            'SHIPOUT_SANDBOX_DISPATCH_DISABLED',
            'SHIPOUT_SANDBOX_READ_DISABLED',
            'SHIPOUT_SANDBOX_WAREHOUSE_NOT_CONFIGURED',
            'SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED',
            'SHIPOUT_PREFLIGHT_STALE',
            'SHIPOUT_ORDER_NOT_APPROVED_IN_SANDBOX_ALLOWLIST',
            'SHIPOUT_PROVIDER_ORDER_ALREADY_EXISTS',
            'SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED',
            'SHIPOUT_ATTEMPT_LEDGER_UNAVAILABLE',
            'SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN',
            'SHIPOUT_HTTP_REJECTED',
            'SHIPOUT_BUSINESS_REJECTED',
            'SHIPOUT_ORDER_RESPONSE_INCOMPLETE',
            'SHIPOUT_ORDER_PAGE_INCOMPLETE',
            'SHIPOUT_ORDER_IDENTITY_AMBIGUOUS',
            'SHIPOUT_ORDER_IDENTITY_MISMATCH',
            'SHIPOUT_ORDER_DETAIL_UNTRUSTED',
            'SHIPOUT_SHIPMENT_WAREHOUSE_MISMATCH',
            'SHIPOUT_SHIPMENTS_UNTRUSTED',
            'SHIPOUT_TRACKING_APPLY_DISABLED',
            'SHIPOUT_NO_VERIFIED_DISPATCH_ATTEMPT',
            'SHIPOUT_PROVIDER_ORDER_IDENTITY_NOT_UNIQUE',
            'SHIPOUT_PROVIDER_ORDER_IDENTITY_MISMATCH',
            'SHIPOUT_NOT_CONFIRMED_SHIPPED',
            'SHIPOUT_TRACKING_CONFIRMATION_MISMATCH',
            'SHIPOUT_SHIPPED_TIME_UNTRUSTED',
            'SHIPOUT_APPLY_STATE_CHANGED',
            'SHIPOUT_CARRIER_NOT_APPROVED_FOR_AUTO_APPLY',
        ];
        $code = in_array($reason, $known, true) ? $reason : 'SHIPOUT_PRECHECK_BLOCKED';
        return response()->json([
            'error_code' => $code,
            'message' => self::messageFor($code),
            'retry_same_order' => false,
            'erp_shipment_unchanged' => true,
            'woo_unchanged' => true,
        ], in_array($code, [
            'SHIPOUT_SANDBOX_DISPATCH_DISABLED',
            'SHIPOUT_SANDBOX_READ_DISABLED',
            'SHIPOUT_SANDBOX_WAREHOUSE_NOT_CONFIGURED',
            'SHIPOUT_TRACKING_APPLY_DISABLED',
        ], true) ? 423 : 409)->header('Cache-Control', 'no-store, private');
    }

    private static function messageFor(string $code): string
    {
        return match ($code) {
            'SHIPOUT_SANDBOX_DISPATCH_DISABLED' => 'ShipOut 沙盒发货开关未启用，未发送订单',
            'SHIPOUT_SANDBOX_READ_DISABLED' => 'ShipOut 沙盒查询未启用，未读取仓库',
            'SHIPOUT_SANDBOX_WAREHOUSE_NOT_CONFIGURED' => 'ShipOut 沙盒仓库 ID 尚未绑定',
            'SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED' => 'Dede 审核、US-LA 仓库或发货状态不符合要求',
            'SHIPOUT_PREFLIGHT_STALE' => '预检结果已经失效，请重新预检，不要重复提交',
            'SHIPOUT_ORDER_NOT_APPROVED_IN_SANDBOX_ALLOWLIST' => '该订单未在沙盒单笔下发白名单',
            'SHIPOUT_PROVIDER_ORDER_ALREADY_EXISTS' => 'ShipOut 中已存在该订单，禁止重复推送',
            'SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED' => '此订单已有 API 发货记录，禁止再次提交，请查询仓库状态',
            'SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN' => 'API 请求结果不明确，可能已接单；禁止重发，请核查 OMS',
            'SHIPOUT_HTTP_REJECTED' => 'ShipOut 接口拒绝请求，请核对授权与接口配置',
            'SHIPOUT_BUSINESS_REJECTED' => 'ShipOut 返回业务拒绝，请核对仓库状态',
            'SHIPOUT_TRACKING_APPLY_DISABLED' => 'ERP 物流回写开关未启用，未修改订单',
            'SHIPOUT_NO_VERIFIED_DISPATCH_ATTEMPT' => '缺少本系统已核实的 API 发货记录，不能直接回写',
            'SHIPOUT_PROVIDER_ORDER_IDENTITY_NOT_UNIQUE' => 'ShipOut 订单匹配不唯一，禁止回写',
            'SHIPOUT_PROVIDER_ORDER_IDENTITY_MISMATCH' => 'ShipOut 订单 ID/订单号不匹配，禁止回写',
            'SHIPOUT_NOT_CONFIRMED_SHIPPED' => '仓库尚未确认实际发货，不能回写',
            'SHIPOUT_TRACKING_CONFIRMATION_MISMATCH' => '确认的 Tracking 与仓库最新回传不一致',
            'SHIPOUT_SHIPPED_TIME_UNTRUSTED' => '仓库发货时间无法验证，禁止回写',
            'SHIPOUT_APPLY_STATE_CHANGED' => 'ERP 订单或审核状态已变化，禁止重复回写',
            'SHIPOUT_CARRIER_NOT_APPROVED_FOR_AUTO_APPLY' => '实际承运商尚未通过自动映射验收',
            default => 'ShipOut 发货或状态校验未通过；未修改 ERP 发货记录',
        };
    }

    private function guardWarehouse(Order $order): void
    {
        $order->loadMissing('shipment.warehouse');
        abort_unless($order->shipment?->warehouse?->code === 'US-LA', 404,
            '只有 MorePlus US-LA 订单可以使用 ShipOut');
    }

    /** @return array<string,mixed>|null */
    private function attempt(Order $order): ?array
    {
        // In default-off Production do not touch an uninstalled UAT table.
        if (! (bool) config('shipout.sandbox_dispatch_enabled', false)) {
            return null;
        }
        $row = DB::table('shipout_dispatch_attempts')
            ->where('order_id', $order->id)->first(['order_id', 'status']);
        return $row ? (array) $row : null;
    }
}
