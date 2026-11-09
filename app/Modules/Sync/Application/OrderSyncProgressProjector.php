<?php

namespace App\Modules\Sync\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderSyncProgressProjector
{
    /**
     * The four operational states are intentionally conservative:
     * a local manual completion is never remote verification, and a Woo
     * completed order is not proof that an email reached the customer.
     *
     * @param  array<string,mixed>  $trackingState
     * @return array{code:string,label:string,reason:?string}
     */
    public function forOrder(Order $order, array $trackingState, bool $isWooOrder): array
    {
        $state = (string) ($trackingState['status'] ?? 'pending');
        $manualStatus = $order->manualSync?->website_sync_status;
        if ($manualStatus instanceof \BackedEnum) {
            $manualStatus = $manualStatus->value;
        }
        $reason = trim((string) ($trackingState['reason'] ?? ''));
        $trackingNumber = trim((string) ($order->shipment?->tracking_number ?? ''));

        if ($state === 'completed') {
            // Only a VERIFIED customer note receipt for this exact shipment
            // qualifies as "已通知". Customer mailbox delivery is not asserted.
            $note = (string) ($order->manualSync?->sync_note ?? '');
            $fingerprint = $trackingNumber !== '' ? 'verified_tracking_sha256:'.hash('sha256', $trackingNumber) : '';
            $verifiedMarker = $order->operationalMarkers->contains('marker', 'woo_manual_tracking_verified');
            $noteVerified = $manualStatus === 'completed'
                && $verifiedMarker
                && $fingerprint !== ''
                && str_contains($note, $fingerprint)
                && preg_match('/Customer Note #[0-9]+ 已接受/u', $note) === 1;

            if ($noteVerified) {
                return ['code' => 'notified', 'label' => '已通知', 'reason' => '官网客户备注已回读确认发送请求，不能保证邮件到达客户邮箱'];
            }

            return ['code' => 'synced', 'label' => '已同步', 'reason' => '已核验官网物流；尚无可确认的客户通知回执'];
        }

        if (in_array($state, ['failed', 'conflict'], true)
            || in_array($manualStatus, ['failed', 'returned', 'needs_confirmation'], true)) {
            return [
                'code' => 'failed',
                'label' => '同步失败',
                'reason' => $reason !== '' ? $reason : ($state === 'conflict' ? '官网物流与 ERP 信息冲突，请人工核对' : '同步异常，需人工检查'),
            ];
        }

        if (! $isWooOrder) {
            return ['code' => 'unsynced', 'label' => '未同步', 'reason' => '此订单没有可确认的 WooCommerce 官网订单关联，不能直接写回'];
        }

        return [
            'code' => 'unsynced',
            'label' => '未同步',
            'reason' => $reason !== '' ? $reason : ($trackingNumber === '' ? '尚未填写发货单号，暂不能同步官网物流' : '尚无可靠的官网物流回读成功记录'),
        ];
    }
}
