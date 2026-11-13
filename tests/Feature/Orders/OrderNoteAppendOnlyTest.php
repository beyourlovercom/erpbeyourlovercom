<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\NotificationCenter\Application\BusinessNotification;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderNoteEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

final class OrderNoteAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_view_only_operator_can_append_without_order_edit_and_original_note_is_unchanged(): void
    {
        $owner = $this->userWith(['orders.view', 'customers.view']);
        $vita = $this->userWith(['orders.view', 'customers.view']);
        $order = $this->order($owner, 'customer', 'Old website note');
        Notification::fake();

        $this->actingAs($vita)->post("/customers/orders/{$order->id}/notes", ['body' => '  补充：库存已核实  '])->assertRedirect();

        $this->assertDatabaseHas('order_note_entries', [
            'order_id' => $order->id, 'author_user_id' => $vita->id,
            'author_name' => $vita->name, 'body' => '补充：库存已核实',
        ]);
        $this->assertSame('Old website note', $order->fresh()->order_note);
        $this->actingAs($owner)->get("/customers/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('canAppendOrderNote', true)
                ->has('orderNotes', 1)
                ->where('orderNotes.0.body', '补充：库存已核实'));
        $this->assertDatabaseHas('audit_records', ['entity_type' => Order::class, 'entity_id' => (string) $order->id, 'action' => 'order.note_appended']);
        Notification::assertSentTo($owner, BusinessNotification::class);
    }

    public function test_two_people_can_append_but_cannot_modify_or_delete_the_prior_entry(): void
    {
        $liang = $this->userWith(['orders.view', 'logistics.view']);
        $vita = $this->userWith(['orders.view', 'customers.view']);
        $order = $this->order($liang);
        $this->actingAs($liang)->post("/customers/orders/{$order->id}/notes", ['body' => 'Liang 原始内容'])->assertRedirect();
        $this->actingAs($vita)->post("/customers/orders/{$order->id}/notes", ['body' => 'Vita 后续更正'])->assertRedirect();

        $this->assertSame(['Liang 原始内容', 'Vita 后续更正'], $order->noteEntries()->pluck('body')->all());
        $first = $order->noteEntries()->firstOrFail();
        $this->expectException(LogicException::class);
        $first->update(['body' => '悄悄替换']);
    }

    public function test_users_cannot_delete_previous_entries_or_edit_legacy_note_through_order_patch(): void
    {
        $actor = $this->userWith(['orders.view', 'orders.edit', 'customers.view']);
        $order = $this->order($actor, 'customer', 'History');
        $this->actingAs($actor)->post("/customers/orders/{$order->id}/notes", ['body' => 'Protect me'])->assertRedirect();
        $entry = $order->noteEntries()->firstOrFail();
        $this->actingAs($actor)->delete("/customers/orders/{$order->id}/notes/{$entry->id}")->assertNotFound();
        foreach (['replace', '', null] as $replacement) {
            $this->actingAs($actor)->patch("/customers/orders/{$order->id}", ['order_note' => $replacement])
                ->assertSessionHasErrors('order_note');
        }
        $this->assertSame('History', $order->fresh()->order_note);
        $this->assertSame('Protect me', $entry->fresh()->body);
    }

    public function test_cannot_append_without_orders_view_or_to_an_archived_order(): void
    {
        $authorized = $this->userWith(['orders.view', 'customers.view']);
        $outsider = $this->userWith(['dashboard.view']);
        $order = $this->order($authorized);
        $this->actingAs($outsider)->post("/customers/orders/{$order->id}/notes", ['body' => 'No access'])->assertForbidden();
        $this->actingAs($authorized)->post("/customers/orders/{$order->id}/notes", ['body' => ' '])->assertSessionHasErrors('body');
        $order->forceFill(['operational_archived_at' => now()])->save();
        $this->actingAs($authorized)->post("/customers/orders/{$order->id}/notes", ['body' => 'Archived'])->assertStatus(409);
        $this->assertDatabaseCount('order_note_entries', 0);
    }

    public function test_kol_specialist_can_append_to_own_order_but_not_another_users_order(): void
    {
        $emma = $this->userWith(['orders.view', 'kol.view']);
        $hiko = $this->userWith(['orders.view', 'kol.view']);
        $own = $this->order($emma, 'kol_fulfillment');
        $foreign = $this->order($hiko, 'kol_fulfillment');
        $this->actingAs($emma)->post("/kol/orders/{$own->id}/notes", ['body' => '我的补充'])->assertRedirect();
        $this->actingAs($emma)->post("/kol/orders/{$foreign->id}/notes", ['body' => '越权'])->assertForbidden();
        $this->actingAs($emma)->post("/customers/orders/{$own->id}/notes", ['body' => '跨类别'])->assertNotFound();
        $this->assertDatabaseCount('order_note_entries', 1);
    }

    public function test_web_source_changes_cannot_overwrite_internal_note_entries(): void
    {
        $actor = $this->userWith(['orders.view', 'customers.view']);
        $order = $this->order($actor, 'customer', 'Source A');
        $this->actingAs($actor)->post("/customers/orders/{$order->id}/notes", ['body' => 'ERP internal note'])->assertRedirect();
        $order->update(['order_note' => 'Source B']);
        $this->assertSame('ERP internal note', $order->noteEntries()->firstOrFail()->body);
        $this->assertSame('Source B', $order->fresh()->order_note);
    }

    public function test_order_list_projects_only_latest_collaboration_note_with_legacy_fallback(): void
    {
        $actor = $this->userWith(['orders.view', 'customers.view']);
        $legacy = $this->order($actor, 'customer', '最初来源备注');
        $updated = $this->order($actor, 'customer', '旧版备注');
        $this->actingAs($actor)->post("/customers/orders/{$updated->id}/notes", ['body' => '第一条新增'])->assertRedirect();
        $this->actingAs($actor)->post("/customers/orders/{$updated->id}/notes", ['body' => '最新：明天发货'])->assertRedirect();

        foreach (['/customers/orders', '/customers/orders?legacy_grid=1', '/orders'] as $url) {
            $result = $this->actingAs($actor)->get($url)->assertOk();
            $result->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.id', $updated->id)
                ->where('orders.data.0.latest_order_note', '最新：明天发货')
                ->where('orders.data.0.order_note', '旧版备注')
                ->where('orders.data.1.id', $legacy->id)
                ->where('orders.data.1.latest_order_note', '最初来源备注'));
        }
        $this->assertSame(2, $updated->noteEntries()->count());
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'note_test_'.uniqid(), 'label' => 'Order Note Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function order(User $owner, string $kind = 'customer', ?string $note = null): Order
    {
        return Order::create([
            'internal_order_number' => 'NOTE-'.uniqid(),
            'erp_order_id' => '007-note-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'NOTE-UAT-'.uniqid(),
            'order_date' => '2026-11-13',
            'order_status' => 'pending_shipment',
            'order_note' => $note,
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
