<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;

/** Fulfillment status is independent of OrderStatus and Shipment.shipment_status. */
final class WarehouseDispatchStatus
{
    private const ACCEPTED = [
        'batch_created', 'batch_draft_created', 'batch_draft_unverified', 'oms_draft_created', 'oms_draft_unverified', 'batch_existing', 'batch_status_updated',
        'submitted_oms', 'found_in_oms',
    ];
    private const RETRYABLE_LOCAL_BLOCKS = [
        'blocked', 'create_disabled', 'preflight_blocked', 'canary_order_not_authorized',
    ];

    public static function canRetryLocalBlock(?XlwmsDispatchAttempt $attempt): bool
    {
        return $attempt !== null && blank($attempt->oms_order_no)
            && $attempt->state === 'batch_blocked'
            && in_array($attempt->failure_code, self::RETRYABLE_LOCAL_BLOCKS, true);
    }

    /** @return array{status:string,label:string,detail:string,oms_order_no:?string,last_checked_at:?string,failure_code:?string,can_retry_local_block:bool} */
    public static function from(string $shipmentStatus, ?XlwmsDispatchAttempt $attempt): array
    {
        $omsNo = trim((string) ($attempt?->oms_order_no ?? ''));
        $code = trim((string) ($attempt?->oms_status_code ?? ''));
        $accepted = $omsNo !== '' && in_array((string)$attempt?->state, self::ACCEPTED, true);
        $status = 'pending_dispatch';
        $label = '待下发';
        $detail = '';
        if ($shipmentStatus === 'shipped' || $shipmentStatus === 'completed') {
            $status = 'shipped'; $label = '已发货';
        } elseif ($accepted && in_array((string)$attempt?->state, ['oms_draft_unverified', 'batch_draft_unverified'], true)) {
            $status = 'exception'; $label = 'OMS 已建单 · 未进入草稿箱确认';
            $detail = $code === '2' ? 'OMS 已进入仓库处理中，非草稿；禁止重复下发'
                : ($code === '4' ? 'OMS 已取消，禁止重复下发'
                    : 'OMS 草稿状态未验证；禁止重复下发');
        } elseif ($accepted && $code === '4') {
            $status = 'exception'; $label = '仓库已取消 · 待处理';
        } elseif ($accepted && $code === '3') {
            $status = 'warehouse_outbound_pending_sync'; $label = '已出库 · 待回写';
        } elseif ($accepted && $code === '0') {
            // Actual OMS readback: code 0 is a DRAFT, not warehouse receipt.
            $status = 'oms_draft'; $label = 'OMS 草稿 · 未提交仓库';
            $detail = '无需面单；仓库接单状态仍需核对';
        } elseif ($accepted && $code === '2') {
            $status = 'exception'; $label = 'OMS 仓库处理中 · 非草稿';
            $detail = '仓库已接单，不能在草稿箱上传面单；禁止重复下发';
        } elseif ($accepted && $code === '5') {
            // Verified by OMS's own 异常 workbench for OBS0272610290RS.
            $status = 'exception'; $label = 'OMS 异常 · 待处理';
            $detail = '仓库未正常接单，请核实 OMS 异常原因';
        } elseif ($accepted) {
            // Unknown code / no status readback is not proof of warehouse receipt.
            $status = 'oms_unverified'; $label = 'OMS 已建单 · 接单待核实';
            if ($code !== '') $detail = 'OMS 状态 '.$code.' · 待核实';
        } elseif ($attempt && (str_contains((string)$attempt->state, 'uncertain')
            || str_contains((string)$attempt->state, 'in_flight')
            || in_array($attempt->state, ['blocked_existing_oms_order', 'batch_identity_blocked'], true))) {
            $status = 'exception'; $label = '结果待核实 · 禁止重发';
        } elseif ($attempt?->failure_code) {
            $detail = match ($attempt->failure_code) {
                'create_disabled' => '未下发 · 建单权限关闭',
                'preflight_blocked' => '未下发 · 订单预检阻断',
                default => '未下发 · '.(string)$attempt->failure_code,
            };
        }
        return [
            'status' => $status, 'label' => $label, 'detail' => $detail,
            'oms_order_no' => $accepted ? $omsNo : null,
            'last_checked_at' => $attempt?->oms_status_checked_at ?? $attempt?->last_checked_at,
            'failure_code' => $attempt?->failure_code,
            'can_retry_local_block' => self::canRetryLocalBlock($attempt),
        ];
    }
}
