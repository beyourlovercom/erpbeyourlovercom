<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NewsletterMarketingM2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.andy_core.newsletter_snapshot_enabled' => true]);
    }

    public function test_two_stream_pagination_and_repeating_imports_keep_one_email_and_two_provenance_rows(): void
    {
        $native = $this->nativeId();
        $fake = $this->bindStreams([
            'legacy' => [
                'start' => $this->page([[
                    'external_subscription_id' => 'elementor_signup:legacy-1', 'email' => ' Alice@Example.test ',
                    'status' => 'subscribed', 'status_updated_at' => '2026-09-01T00:00:00Z',
                ]], 'legacy-page-2'),
                'legacy-page-2' => $this->page([], null),
            ],
            'andy_core_newsletter' => [
                'start' => $this->page([[
                    'external_subscription_id' => $native, 'email' => 'alice@example.test',
                    'status' => 'pending', 'consent_source' => 'website_shortcode',
                    'status_updated_at' => '2026-09-02T00:00:00Z',
                ]], 'native-page-2'),
                'native-page-2' => $this->page([], null),
            ],
        ]);
        $operator = $this->operator();
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();

        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseCount('marketing_subscriptions', 2);
        $this->assertDatabaseHas('marketing_subscribers', ['email' => 'alice@example.test']);
        $this->assertDatabaseHas('marketing_subscriptions', [
            'external_subscription_id' => $native, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('marketing_subscriptions', [
            'external_subscription_id' => 'elementor_signup:legacy-1', 'status' => 'subscribed',
        ]);
        $this->assertSame([null, 'legacy-page-2', null, 'native-page-2', null, 'legacy-page-2', null, 'native-page-2'],
            array_column($fake->requests, 'cursor'));
        $this->assertSame([null, null, 'andy_core_newsletter', 'andy_core_newsletter',
            null, null, 'andy_core_newsletter', 'andy_core_newsletter'], array_column($fake->requests, 'source'));
    }

    public function test_native_unsubscribe_cannot_regress_from_out_of_order_or_equal_time_snapshot(): void
    {
        $id = $this->nativeId();
        $operator = $this->operator();
        $this->bindNative([$this->item($id, 'subscribed', '2026-09-01T00:00:00Z')]);
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->bindNative([$this->item($id, 'unsubscribed', '2026-09-03T00:00:00Z')]);
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->bindNative([$this->item($id, 'subscribed', '2026-09-02T00:00:00Z')]);
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        $this->bindNative([$this->item($id, 'subscribed', '2026-09-03T00:00:00Z')]);
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => $id, 'status' => 'unsubscribed']);
        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseCount('marketing_subscriptions', 1);
        $this->assertDatabaseHas('sync_run_items', ['status' => 'failed', 'error_code' => 'invalid_item']);
    }

    public function test_explicit_reconsent_requires_new_pending_before_resubscribing(): void
    {
        $id = $this->nativeId();
        $user = $this->operator();
        $steps = [
            ['pending', '2026-09-01T00:00:00Z'],
            ['subscribed', '2026-09-02T00:00:00Z'],
            ['unsubscribed', '2026-09-03T00:00:00Z'],
            ['pending', '2026-09-04T00:00:00Z'],
            ['subscribed', '2026-09-05T00:00:00Z'],
        ];
        foreach ($steps as [$status, $timestamp]) {
            $this->bindNative([$this->item($id, $status, $timestamp)]);
            $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertRedirect();
            $this->assertDatabaseHas('marketing_subscriptions', [
                'external_subscription_id' => $id, 'status' => $status,
            ]);
        }
        $this->assertDatabaseCount('marketing_subscriptions', 1);
        $this->assertDatabaseCount('marketing_subscribers', 1);
    }

    public function test_suppressed_is_never_automatically_released(): void
    {
        $id = $this->nativeId();
        $user = $this->operator();
        $this->bindNative([$this->item($id, 'suppressed', '2026-09-03T00:00:00Z')]);
        $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->bindNative([$this->item($id, 'pending', '2026-09-04T00:00:00Z')]);
        $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => $id, 'status' => 'suppressed']);
    }

    public function test_bad_native_id_source_status_and_timestamps_never_create_rows(): void
    {
        $user = $this->operator();
        $examples = [
            ['external_subscription_id' => 'other-system:fake'],
            ['status' => 'unknown'],
            ['provider' => 'woocommerce'],
            ['source_site' => 'other.example'],
            ['status_updated_at' => null],
            ['status_updated_at' => '2026-09-02'],
            ['email' => 'not-an-email'],
        ];
        foreach ($examples as $change) {
            $this->bindNative([array_replace($this->item($this->nativeId(), 'pending', '2026-09-02T00:00:00Z'), $change)]);
            $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        }
        $this->assertDatabaseCount('marketing_subscribers', 0);
        $this->assertDatabaseCount('marketing_subscriptions', 0);
    }

    public function test_native_cursor_repeat_records_provider_failure_and_does_not_loop(): void
    {
        $this->bindStreams([
            'legacy' => ['start' => $this->page([])],
            'andy_core_newsletter' => [
                'start' => $this->page([], 'repeat'),
                'repeat' => $this->page([], 'repeat'),
            ],
        ]);
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        $this->assertDatabaseHas('sync_run_items', [
            'operation' => 'wordpress.subscriber_snapshot', 'status' => 'failed',
        ]);
        $this->assertDatabaseCount('marketing_subscribers', 0);
    }

    public function test_native_provider_error_is_reported_not_silent_success(): void
    {
        $this->bindStreams([
            'legacy' => ['start' => $this->page([])],
            'andy_core_newsletter' => [],
        ]);
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertSessionHas('error');
        $this->assertDatabaseHas('sync_run_items', [
            'operation' => 'wordpress.subscriber_snapshot', 'status' => 'failed',
        ]);
    }

    public function test_default_gate_uses_only_legacy_without_source(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => false]);
        $fake = $this->bindStreams(['legacy' => ['start' => $this->page([])]]);
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->assertCount(1, $fake->requests);
        $this->assertNull($fake->requests[0]['source']);
    }

    private function nativeId(): string
    {
        return 'andy_core_newsletter:'.str_repeat('a', 64);
    }

    private function item(string $id, string $status, string $timestamp): array
    {
        return [
            'external_subscription_id' => $id, 'email' => 'ALICE@example.test',
            'provider' => 'wordpress', 'source_site' => 'beyourlover.com',
            'status' => $status, 'status_updated_at' => $timestamp,
            'consent_source' => 'newsletter_checkbox_v1',
            'subscribed_at' => $status === 'subscribed' ? $timestamp : null,
            'unsubscribed_at' => $status === 'unsubscribed' ? $timestamp : null,
        ];
    }

    private function page(array $items, ?string $next = null): array
    {
        return [
            'items' => $items, 'next_cursor' => $next,
            'source_site' => 'beyourlover.com', 'request_id' => 'fake-signed-source',
        ];
    }

    private function bindNative(array $items): object
    {
        return $this->bindStreams([
            'legacy' => ['start' => $this->page([])],
            'andy_core_newsletter' => ['start' => $this->page($items)],
        ]);
    }

    private function bindStreams(array $pages): object
    {
        $fake = new class($pages) implements WordPressSubscriberGateway {
            public array $requests = [];
            public function __construct(private array $streams) {}
            public function descriptor(): array {
                return ['configured' => true, 'url' => 'https://wp.example.test',
                    'connection_key' => 'beyourlover.com'];
            }
            public function snapshotSubscribers(
                ?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null
            ): array {
                $this->requests[] = compact('cursor', 'updatedAfter', 'limit', 'source');
                $stream = $this->streams[$source ?? 'legacy'] ?? null;
                if (! is_array($stream) || ! isset($stream[$cursor ?? 'start'])) {
                    throw new \RuntimeException('Synthetic provider unavailable.');
                }
                return [...$stream[$cursor ?? 'start'], 'source' => $source];
            }
        };
        $this->app->instance(WordPressSubscriberGateway::class, $fake);
        return $fake;
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'm2-newsletter-'.uniqid(), 'label' => 'Marketing M2']);
        foreach (['sync.view', 'sync.operate', 'marketing.view', 'marketing.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
