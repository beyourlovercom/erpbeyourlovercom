<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncOutboxSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_outbox_schema_matches_the_frozen_contract(): void
    {
        $this->assertTrue(Schema::hasTable('sync_outbox'));
        $this->assertSame([
            'id',
            'idempotency_key',
            'operation',
            'aggregate_type',
            'aggregate_id',
            'provider_key',
            'connection_key',
            'payload',
            'status',
            'attempts',
            'max_attempts',
            'available_at',
            'locked_at',
            'lock_token',
            'last_attempted_at',
            'completed_at',
            'failed_at',
            'last_error_code',
            'last_error_message',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('sync_outbox'));

        $columns = collect(Schema::getColumns('sync_outbox'))->keyBy('name');

        $this->assertSame('pending', trim((string) $columns['status']['default'], "'\""));
        $this->assertSame('0', (string) $columns['attempts']['default']);
        $this->assertSame('3', (string) $columns['max_attempts']['default']);

        foreach (['id', 'idempotency_key', 'operation', 'status', 'attempts', 'max_attempts', 'available_at'] as $column) {
            $this->assertFalse($columns[$column]['nullable'], $column.' must not be nullable.');
        }

        foreach (['aggregate_type', 'aggregate_id', 'provider_key', 'connection_key', 'payload', 'locked_at', 'lock_token', 'last_attempted_at', 'completed_at', 'failed_at', 'last_error_code', 'last_error_message', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue($columns[$column]['nullable'], $column.' must be nullable.');
        }
    }

    public function test_sync_outbox_indexes_match_the_frozen_contract(): void
    {
        $indexes = collect(Schema::getIndexes('sync_outbox'))->keyBy('name');

        $this->assertTrue($indexes['sync_outbox_idempotency_key_unique']['unique']);
        $this->assertSame(['idempotency_key'], $indexes['sync_outbox_idempotency_key_unique']['columns']);
        $this->assertSame(['status', 'available_at'], $indexes['sync_outbox_claim_index']['columns']);
        $this->assertSame(['status', 'locked_at'], $indexes['sync_outbox_stale_index']['columns']);
        $this->assertSame(['aggregate_type', 'aggregate_id'], $indexes['sync_outbox_aggregate_index']['columns']);
        $this->assertSame(['provider_key', 'connection_key'], $indexes['sync_outbox_provider_connection_index']['columns']);
        $this->assertSame(['lock_token'], $indexes['sync_outbox_lock_token_index']['columns']);
    }
}
