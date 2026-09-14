<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class DeliveryEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_completion_blocks_without_delivery_evidence(): void
    {
        [$order, $shipment] = $this->scenario();

        $this->assertHttpStatus(
            fn () => app(ShipmentService::class)->markCompleted($order, null),
            409,
            '承运商已签收证据',
        );

        $this->assertSame('shipped', $shipment->fresh()->shipment_status);
    }

    public function test_direct_completion_blocks_non_delivered_evidence(): void
    {
        foreach (['in_transit', 'exception'] as $deliveryStatus) {
            [$order, $shipment] = $this->scenario();
            $this->billing($shipment, $deliveryStatus);

            $this->assertHttpStatus(
                fn () => app(ShipmentService::class)->markCompleted($order, null),
                409,
                '承运商已签收证据',
            );

            $this->assertSame('shipped', $shipment->fresh()->shipment_status);
        }
    }

    public function test_direct_completion_accepts_delivered_evidence(): void
    {
        [$order, $shipment] = $this->scenario();
        $this->billing($shipment, ' delivered ');

        app(ShipmentService::class)->markCompleted($order, null);

        $this->assertSame('completed', $shipment->fresh()->shipment_status);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'shipment.completed',
            'entity_id' => (string) $shipment->id,
        ]);
    }

    public function test_review_completion_requires_delivery_evidence_but_not_exact_shipped_at(): void
    {
        [$order, $shipment] = $this->scenario('exception', null);
        $review = $this->review($order);

        $this->assertHttpStatus(
            fn () => app(ShipmentService::class)->decideReview($order, 'completed', 'carrier delivered', null),
            422,
            '承运商已签收证据',
        );
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertSame('exception', $shipment->fresh()->shipment_status);

        $this->billing($shipment, 'delivered');
        app(ShipmentService::class)->decideReview($order, 'completed', 'carrier delivered', null);

        $this->assertSame('completed', $shipment->fresh()->shipment_status);
        $this->assertNull($shipment->fresh()->shipped_at);
        $this->assertSame('decided', $review->fresh()->status);
        $this->assertSame('completed', $review->fresh()->destination);
        $this->assertSame('shipped', $order->fresh()->order_status->value);
    }

    /** @return array{0:Order,1:Shipment} */
    private function scenario(string $shipmentStatus = 'shipped', ?string $shippedAt = '2026-09-14 02:00:00'): array
    {
        $suffix = uniqid();
        $order = Order::create([
            'internal_order_number' => 'DELIVERY-GATE-'.$suffix,
            'erp_order_id' => '007-0914-c'.$suffix,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'DELIVERY-'.$suffix,
            'order_date' => '2026-09-14',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $shipment = $order->shipment()->create([
            'shipment_status' => $shipmentStatus,
            'carrier' => 'USPS',
            'tracking_number' => 'TRACK'.$suffix,
            'shipped_at' => $shippedAt,
        ]);

        return [$order, $shipment];
    }

    private function billing(Shipment $shipment, string $deliveryStatus): void
    {
        $shipment->billingEvidence()->create([
            'delivery_status' => $deliveryStatus,
            'data_source' => 'carrier_import',
            'provider_reference' => 'EVIDENCE-'.uniqid(),
        ]);
    }

    private function review(Order $order): LogisticsReview
    {
        return LogisticsReview::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'requested_at' => now(),
            'reason' => 'delivery evidence review',
            'trigger' => 'uat',
        ]);
    }

    private function assertHttpStatus(callable $callback, int $status, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP exception was not thrown.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
