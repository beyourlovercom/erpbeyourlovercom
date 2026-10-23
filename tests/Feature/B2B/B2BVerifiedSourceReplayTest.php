<?php

namespace Tests\Feature\B2B;

use App\Models\User;
use App\Modules\B2B\Infrastructure\B2BVerifiedSourceIntake;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BVerifiedSourceReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_source_replay_maps_to_one_case_and_one_activity(): void
    {
        $owner = User::factory()->create();
        $org = B2BOrganization::create(['display_name' => 'Synthetic Brand Distributor', 'owner_id' => $owner->id]);
        $ingestor = app(B2BVerifiedSourceIntake::class);

        $first = $ingestor->ingest('website_form', 'beyourlover.example.invalid', 'form-001', $org, $owner->id, 'Trial Quantity Inquiry');
        $second = $ingestor->ingest('website_form', 'beyourlover.example.invalid', 'form-001', $org, $owner->id, 'Trial Quantity Inquiry');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('b2b_cases', 1);
        $this->assertDatabaseCount('b2b_case_activities', 1);
        $this->assertDatabaseHas('b2b_cases', [
            'id' => $first->id,
            'source_channel' => 'website_form',
            'source_external_id' => 'form-001',
        ]);
    }

    public function test_same_provider_identity_is_quarantined_when_it_claims_different_org(): void
    {
        $owner = User::factory()->create();
        $a = B2BOrganization::create(['display_name' => 'Company A', 'owner_id' => $owner->id]);
        $b = B2BOrganization::create(['display_name' => 'Company B', 'owner_id' => $owner->id]);
        $ingestor = app(B2BVerifiedSourceIntake::class);
        $ingestor->ingest('website_form', 'connection-1', 'message-77', $a, $owner->id, 'First');
        try {
            $ingestor->ingest('website_form', 'connection-1', 'message-77', $b, $owner->id, 'Conflicting');
            $this->fail('Expected a source identity conflict');
        } catch (DomainException $exception) {
            $this->assertSame('Provider source identity conflict', $exception->getMessage());
        }

        $this->assertDatabaseCount('b2b_cases', 1);
        $this->assertDatabaseCount('b2b_case_activities', 1);
    }

    public function test_manual_unverified_channel_cannot_use_provider_ingestor(): void
    {
        $owner = User::factory()->create();
        $org = B2BOrganization::create(['display_name' => 'Manual Company', 'owner_id' => $owner->id]);
        $this->expectException(\InvalidArgumentException::class);
        app(B2BVerifiedSourceIntake::class)
            ->ingest('manual', 'operator', '1', $org, $owner->id, 'Unverified source');
    }
}
