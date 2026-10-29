<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\ShipOut\ShipOutTrackingDecision;
use PHPUnit\Framework\TestCase;

final class ShipOutTrackingDecisionTest extends TestCase
{
    public function test_order_acknowledgement_is_never_shipped(): void
    {
        $result = (new ShipOutTrackingDecision())->evaluate([
            'status' => 2,
            'shipments' => [[
                'outboundStatus' => 2, 'trackingNumber' => 'TRACK-NOT-READY',
                'shippedTime' => '2026-10-29 10:00:00', 'carrierId' => 1,
            ]],
        ]);
        $this->assertSame('pending', $result['status']);
    }

    public function test_only_fully_shipped_with_tracking_becomes_review_candidate(): void
    {
        $result = (new ShipOutTrackingDecision())->evaluate([
            'status' => 4,
            'shipments' => [[
                'shipmentId' => 'SHIPOUT-1',
                'outboundStatus' => 4, 'labelStatus' => 3,
                'trackingNumber' => 'USPS9400TRACK', 'shippedTime' => '2026-10-29 10:00:00',
                'carrierId' => 1,
            ]],
        ]);
        $this->assertSame('ready_for_erp_review', $result['status']);
        $this->assertSame('USPS9400TRACK', $result['tracking_number']);
        $this->assertSame('2026-10-29 10:00:00', $result['shipped_time_utc']);
    }

    public function test_cancelled_and_aborted_block_without_erp_write(): void
    {
        $this->assertSame('blocked', (new ShipOutTrackingDecision())->evaluate(['status'=>6])['status']);
        $this->assertSame('blocked', (new ShipOutTrackingDecision())->evaluate(['status'=>11])['status']);
    }

    public function test_multi_shipment_requires_manual_review(): void
    {
        $this->assertSame('blocked', (new ShipOutTrackingDecision())->evaluate([
            'status'=>4,'shipments'=>[[], []],
        ])['status']);
    }

    public function test_tracking_or_timestamp_missing_blocks_completion(): void
    {
        foreach ([
            ['', '2026-10-29 10:00:00'],
            ['USPS9400TRACK', ''],
        ] as [$tracking, $date]) {
            $this->assertSame('blocked', (new ShipOutTrackingDecision())->evaluate([
                'status'=>4, 'shipments'=>[[
                    'outboundStatus'=>4,'trackingNumber'=>$tracking,
                    'shippedTime'=>$date,'carrierId'=>1,
                ]],
            ])['status']);
        }
    }
}
