<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ShipOutProductionOmsReadbackTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(int $id): object
    {
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $id, 'order_no' => 'R12-TEST-'.$id,
            'provider_warehouse_id' => ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID,
            'payload_hash' => str_repeat('a', 64),
            'status' => 'acknowledged',
            'provider_order_id' => 'OMS-TEST-'.$id,
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('shipout_dispatch_attempts')->where('order_id', $id)->first();
    }

    /** @return array<string,mixed> */
    private function providerResult(int $id, int $code = 3): array
    {
        return [
            'order_id' => $id, 'attempt_status' => 'acknowledged',
            'provider_order_id' => 'OMS-TEST-'.$id,
            'provider_status' => $code,
            'reconciliation' => $code === 6
                ? 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW'
                : 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY',
            'provider_failure_code' => $code === 6 ? 'PROVIDER_DUPLICATE_ORDER' : null,
            'shipped' => false, 'tracking_apply' => false,
        ];
    }

    public function test_ack_first_get_second_get_and_later_cancel_are_immutable_and_correctly_classified(): void
    {
        Http::preventStrayRequests();
        $id = 8801;
        $attempt = $this->attempt($id);
        $record = app(ShipOutProductionOmsReadbackService::class);

        $this->assertSame('oms_ack_pending', ShipOutProductionDispatchState::from($attempt)['status']);
        $record->recordResult($id, $this->providerResult($id));
        $first = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('visible', $first->state);
        $this->assertSame('oms_ack_pending', ShipOutProductionDispatchState::from($attempt, null, $first)['status']);

        DB::table('shipout_oms_readbacks')->where('id', $first->id)
            ->update(['checked_at' => now()->subMinutes(3)]);
        $record->recordResult($id, $this->providerResult($id));
        $second = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('confirmed', $second->state);
        $this->assertSame('oms_confirmed', ShipOutProductionDispatchState::from($attempt, null, $second)['status']);

        $record->recordResult($id, $this->providerResult($id, 6));
        $cancel = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('cancelled', $cancel->state);
        $this->assertSame('PROVIDER_DUPLICATE_ORDER', $cancel->reason_code);
        $this->assertSame('oms_quarantined', ShipOutProductionDispatchState::from($attempt, null, $cancel)['status']);

        // A later provider oscillation cannot automatically resurrect the
        // cancelled order; Dede must confirm in the original OMS.
        $record->recordResult($id, $this->providerResult($id));
        $last = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('cancelled', $last->state);
        $this->assertSame('PROVIDER_DUPLICATE_ORDER', $last->reason_code);
        $this->assertDatabaseCount('shipout_oms_readbacks', 4);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $id, 'status' => 'acknowledged',
            'provider_order_id' => 'OMS-TEST-'.$id,
        ]);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_unverified_and_provider_id_change_are_never_success(): void
    {
        Http::preventStrayRequests();
        $id = 8802;
        $attempt = $this->attempt($id);
        $record = app(ShipOutProductionOmsReadbackService::class);
        $uncertain = $this->providerResult($id);
        $uncertain['provider_status'] = null;
        $uncertain['reconciliation'] = 'NOT_YET_VISIBLE_MANUAL_REVIEW';
        $record->recordResult($id, $uncertain);
        $snapshot = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('pending', $snapshot->state);
        $this->assertFalse(ShipOutProductionDispatchState::from($attempt, null, $snapshot)['is_oms_created']);

        $bad = $this->providerResult($id);
        $bad['provider_order_id'] = 'OTHER-OMS-ORDER';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHIPOUT_R12_OMS_ID_MISMATCH');
        $record->recordResult($id, $bad);
        Http::assertNothingSent();
    }

    public function test_oms_periodic_command_is_disabled_in_dev_and_cannot_send(): void
    {
        Http::preventStrayRequests();
        config()->set('shipout.oms_reconciliation_enabled', true);
        $this->artisan('shipout:reconcile-oms --apply')
            ->assertExitCode(0);
        $this->assertDatabaseCount('shipout_oms_readbacks', 0);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_readback_is_quarantined_not_treated_as_confirmed(): void
    {
        $id = 8803;
        $attempt = $this->attempt($id);
        app(ShipOutProductionOmsReadbackService::class)->recordResult($id, [
            'attempt_status' => 'acknowledged',
            'provider_order_id' => 'OMS-TEST-'.$id,
            'provider_status' => null,
            'reconciliation' => 'OMS_ORDER_WITHOUT_ERP_LEDGER_MANUAL_REVIEW',
        ]);
        $last = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        $this->assertSame('unverified', $last->state);
        $this->assertSame('oms_quarantined', ShipOutProductionDispatchState::from($attempt, null, $last)['status']);
    }
}
